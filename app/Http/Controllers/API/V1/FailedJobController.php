<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemLog;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FailedJobController extends Controller
{
    /**
     * List failed jobs (DLQ) with pagination.
     */
    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 20), 100);

        $query = DB::table('failed_jobs')
            ->orderByDesc('failed_at');

        // Optional queue filter
        if ($request->filled('queue')) {
            $query->where('queue', $request->get('queue'));
        }

        // Optional keyword search in exception column
        if ($request->filled('search')) {
            $query->where('exception', 'like', '%' . $request->get('search') . '%');
        }

        $total   = (clone $query)->count();
        $items   = $query->paginate($perPage);

        $mapped = collect($items->items())->map(function ($job) {
            $payload = json_decode($job->payload ?? '{}', true);

            return [
                'id'          => $job->id,
                'uuid'        => $job->uuid,
                'queue'       => $job->queue,
                'job_class'   => data_get($payload, 'displayName', 'Unknown'),
                'tenant_name' => $this->resolveTenantName($payload),
                'failed_at'   => $job->failed_at,
                'age_minutes' => now()->diffInMinutes(Carbon::parse($job->failed_at)),
                'exception'   => $job->exception,
                'payload'     => $payload,
            ];
        });

        return response()->json([
            'data' => $mapped,
            'meta' => [
                'total'        => $total,
                'current_page' => $items->currentPage(),
                'per_page'     => $items->perPage(),
                'last_page'    => $items->lastPage(),
            ],
            'summary' => [
                'total_failed' => $total,
                'oldest_age_minutes' => $total > 0
                    ? now()->diffInMinutes(\Carbon\Carbon::parse(
                        DB::table('failed_jobs')->orderBy('failed_at')->value('failed_at')
                    ))
                    : 0,
            ],
        ]);
    }

    /**
     * Show a single failed job's full detail.
     */
    public function show(string $uuid)
    {
        $job = DB::table('failed_jobs')->where('uuid', $uuid)->first();

        if (!$job) {
            return response()->json(['message' => 'Failed job not found.'], 404);
        }

        $payload = json_decode($job->payload ?? '{}', true);

        return response()->json([
            'id'          => $job->id,
            'uuid'        => $job->uuid,
            'queue'       => $job->queue,
            'job_class'   => data_get($payload, 'displayName', 'Unknown'),
            'tenant_name' => $this->resolveTenantName($payload),
            'failed_at'   => $job->failed_at,
            'age_minutes' => now()->diffInMinutes(Carbon::parse($job->failed_at)),
            'exception'   => $job->exception,
            'payload'     => $payload,
        ]);
    }

    /**
     * Retry a single failed job (re-queue it).
     */
    public function retry(Request $request, string $uuid)
    {
        $job = DB::table('failed_jobs')->where('uuid', $uuid)->first();

        if (!$job) {
            return response()->json(['message' => 'Failed job not found.'], 404);
        }

        try {
            Artisan::call('queue:retry', ['id' => [$uuid]]);

            // Audit log
            SystemLog::create([
                'type'           => 'queue',
                'log_type'       => 'DLQ_JOB_RETRIED',
                'severity'       => 'info',
                'terminal_uid'   => null,
                'transaction_id' => null,
                'message'        => 'Failed job manually retried from DLQ',
                'context'        => [
                    'uuid'       => $uuid,
                    'queue'      => $job->queue,
                    'failed_at'  => $job->failed_at,
                    'retried_by' => optional(auth())->id(),
                ],
            ]);

            Log::info('[DLQ] Job manually retried', ['uuid' => $uuid]);

            return response()->json([
                'message' => 'Job queued for retry.',
                'uuid'    => $uuid,
            ]);
        } catch (\Throwable $e) {
            Log::error('[DLQ] Retry failed', ['uuid' => $uuid, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Retry failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Retry all failed jobs.
     */
    public function retryAll()
    {
        $count = DB::table('failed_jobs')->count();

        if ($count === 0) {
            return response()->json(['message' => 'No failed jobs to retry.']);
        }

        try {
            Artisan::call('queue:retry', ['id' => ['all']]);

            SystemLog::create([
                'type'           => 'queue',
                'log_type'       => 'DLQ_ALL_RETRIED',
                'severity'       => 'warning',
                'terminal_uid'   => null,
                'transaction_id' => null,
                'message'        => "All {$count} failed DLQ jobs triggered for retry",
                'context'        => [
                    'count'      => $count,
                    'retried_by' => optional(auth())->id(),
                ],
            ]);

            return response()->json(['message' => "Retrying all {$count} failed jobs.", 'count' => $count]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Retry all failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Flush (permanently delete) a single failed job.
     */
    public function flush(string $uuid)
    {
        $deleted = DB::table('failed_jobs')->where('uuid', $uuid)->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Failed job not found.'], 404);
        }

        SystemLog::create([
            'type'           => 'queue',
            'log_type'       => 'DLQ_JOB_FLUSHED',
            'severity'       => 'warning',
            'terminal_uid'   => null,
            'transaction_id' => null,
            'message'        => 'Failed job permanently deleted from DLQ',
            'context'        => ['uuid' => $uuid, 'flushed_by' => optional(auth())->id()],
        ]);

        return response()->json(['message' => 'Failed job deleted.', 'uuid' => $uuid]);
    }

    /**
     * DLQ health summary (for stat card).
     */
    public function stats()
    {
        $total = DB::table('failed_jobs')->count();

        $oldest = $total > 0
            ? now()->diffInMinutes(
                \Carbon\Carbon::parse(DB::table('failed_jobs')->orderBy('failed_at')->value('failed_at'))
              )
            : 0;

        $byQueue = DB::table('failed_jobs')
            ->select('queue', DB::raw('count(*) as count'))
            ->groupBy('queue')
            ->get();

        return response()->json([
            'total_failed'       => $total,
            'oldest_age_minutes' => $oldest,
            'by_queue'           => $byQueue,
            'threshold_exceeded' => $total >= (int) config('tsms.dlq.alert_threshold', 10),
        ]);
    }

    /**
     * Resolve tenant name from job payload if possible.
     */
    private function resolveTenantName(array $payload): ?string
    {
        $command = data_get($payload, 'data.command');
        if (!$command) {
            return null;
        }

        // Try to find transactionId in serialized command
        // Patterns: s:16:"*transactionId";i:12345; or s:13:"transactionId";i:12345;
        if (preg_match('/transactionId";i:(\d+)/', $command, $matches)) {
            $txnId = (int) $matches[1];
            try {
                $txn = Transaction::with('tenant')->find($txnId);
                return $txn?->tenant?->name;
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }
}
