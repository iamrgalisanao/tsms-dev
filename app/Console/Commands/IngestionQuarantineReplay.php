<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\IngestionQuarantine;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class IngestionQuarantineReplay extends Command
{
    protected $signature = 'ingestion:quarantine:replay {id} {--execute : Actually attempt the replay (safe=false)} {--force : Bypass policy checks}';

    protected $description = 'Attempt to replay a quarantined payload back through ingestion (safe skeleton)';

    public function handle(): int
    {
        $id = $this->argument('id');
        $execute = (bool) $this->option('execute');

        $row = IngestionQuarantine::find($id);
        if (!$row) {
            $this->error("Quarantine record not found: {$id}");
            return 1;
        }

        if (in_array($row->status, ['replayed', 'processing'], true)) {
            $this->warn('Record already processed or in-progress: ' . $row->status);
            return 0;
        }

        // Increment attempts safely
        try {
            DB::transaction(function () use ($row) {
                $row->attempts = ($row->attempts ?? 0) + 1;
                $row->status = 'processing';
                $row->save();
            });
        } catch (\Throwable $e) {
            $this->error('Failed to mark attempt: ' . $e->getMessage());
            Log::warning('quarantine:replay failed to mark attempt', ['id' => $id, 'error' => $e->getMessage()]);
            return 1;
        }

        $this->info('Marked attempt #' . $row->attempts);

        // Policy check: executing a replay which re-posts payloads can be dangerous.
        // Allow only when config flag is enabled or when operator passes --force.
        if ($execute) {
            $allowed = config('ingestion.quarantine.allow_replay_execute', false);
            if (!$allowed && !$this->option('force')) {
                $this->warn('Replay --execute is disabled by configuration.');
                if (!$this->confirm('Do you want to proceed with replay execution?')) {
                    $this->line('Aborted by operator.');
                    // Reset processing marker that we set earlier
                    $row->status = 'pending';
                    $row->save();
                    return 1;
                }
            }
        }

        if (!$execute) {
            $this->line('Dry-run: not executing replay. Use --execute to actually attempt ingestion.');
            // Reset processing marker back to pending so ops can re-run later
            $row->status = 'pending';
            $row->save();
            return 0;
        }

        // Execute: attempt to re-post payload in-process via the application's router
        try {
            $payload = $row->payload;
            // create a request that mimics the original POST to the official endpoint
            $request = Request::create('/api/v1/transactions/official', 'POST', [], [], [], [], $payload);
            $request->headers->set('Content-Type', 'application/json');

            $this->line('Dispatching in-process request to /api/v1/transactions/official');
            $response = app()->handle($request);

            $status = $response->getStatusCode();
            $this->info('Ingestion response status: ' . $status);

            if ($status >= 200 && $status < 300) {
                $row->status = 'replayed';
                $row->replayed_at = now();
                $row->save();
                $this->info('Replay succeeded and quarantine marked as replayed.');
                return 0;
            }

            // treat non-2xx as failure but keep attempt count
            $row->status = 'failed';
            $row->save();
            $this->error('Replay attempt returned non-success HTTP status: ' . $status);
            return 1;

        } catch (\Throwable $e) {
            $row->status = 'failed';
            $row->save();
            $this->error('Replay attempt failed: ' . $e->getMessage());
            Log::error('quarantine:replay exception', ['id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return 1;
        }
    }
}
