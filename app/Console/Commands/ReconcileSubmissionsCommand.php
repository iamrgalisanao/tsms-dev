<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Models\SubmissionEvent;
use App\Models\Transaction;

/**
 * Reconcile POS vs TSMS using submission_events (durable ledger) and transactions.
 *
 * Outputs a summary per day or per submission and can export a CSV file for ops.
 *
 * Examples:
 *  php artisan tsms:reconcile --from=2025-10-01 --to=2025-10-21 --tenant=1 --per=day --csv=storage/app/recon.csv
 *  php artisan tsms:reconcile --per=submission --include-rejected
 */
class ReconcileSubmissionsCommand extends Command
{
    protected $signature = 'tsms:reconcile
        {--tenant= : Filter by tenant_id}
        {--terminal= : Filter by terminal_id}
        {--from= : Start date (YYYY-MM-DD)}
        {--to= : End date (YYYY-MM-DD, inclusive)}
        {--per=day : Grouping: day|submission}
        {--csv= : Optional path to write CSV output}
        {--tz=UTC : Timezone for date grouping}
        {--include-rejected : Include rejected-only submissions in CSV/detail output}
    ';

    protected $description = 'Reconcile submission events with processed transactions and optionally export CSV.';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $terminalId = $this->option('terminal');
        $from = $this->option('from');
        $to = $this->option('to');
        $grouping = strtolower((string) $this->option('per') ?: 'day');
        $csvPath = $this->option('csv');
        $tz = $this->option('tz') ?: 'UTC';
        $includeRejected = (bool) $this->option('include-rejected');

        if (!in_array($grouping, ['day', 'submission'], true)) {
            $this->error("Invalid --per value: {$grouping}. Use 'day' or 'submission'.");
            return self::INVALID;
        }

        // Date bounds
        $fromDt = $from ? Carbon::parse($from, $tz)->startOfDay() : null;
        $toDt = $to ? Carbon::parse($to, $tz)->endOfDay() : null;

        // Load submission events within range and scope
        $eventsQuery = SubmissionEvent::query();
        if ($tenantId !== null && $tenantId !== '') {
            $eventsQuery->where('tenant_id', (int) $tenantId);
        }
        if ($terminalId !== null && $terminalId !== '') {
            $eventsQuery->where('terminal_id', (int) $terminalId);
        }
        if ($fromDt) { $eventsQuery->where('occurred_at', '>=', $fromDt->clone()->setTimezone('UTC')); }
        if ($toDt) { $eventsQuery->where('occurred_at', '<=', $toDt->clone()->setTimezone('UTC')); }

        /** @var Collection<int, SubmissionEvent> $events */
        $events = $eventsQuery
            ->orderBy('submission_uuid')
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->get();

        if ($events->isEmpty()) {
            $this->warn('No submission events found for the provided filters.');
            return self::SUCCESS;
        }

        // Reduce to latest event per submission for final status and pick a representative declared count
        $bySubmission = $events->groupBy('submission_uuid')->map(function (Collection $rows) {
            // latest row is first due to orderByDesc
            $latest = $rows->first();
            // Prefer a RECEIVED event for declared count, else fallback to latest
            $declared = $rows->firstWhere('status', 'RECEIVED') ?: $latest;
            return [
                'submission_uuid'   => $latest->submission_uuid,
                'tenant_id'         => $latest->tenant_id,
                'terminal_id'       => $latest->terminal_id,
                'final_status'      => strtoupper((string) $latest->status),
                'declared_count'    => (int) ($declared->transaction_count ?? 0),
                'occurred_at'       => $latest->occurred_at,
                'reason_code'       => $latest->reason_code,
                'reason_details'    => $latest->reason_details,
            ];
        })->values();

        // Fetch processed transactions per submission_uuid in one query
        $submissionUuids = $bySubmission->pluck('submission_uuid')->filter()->unique()->all();
        $txnCounts = Transaction::query()
            ->select('submission_uuid', DB::raw('COUNT(*) as cnt'))
            ->whereIn('submission_uuid', $submissionUuids)
            ->groupBy('submission_uuid')
            ->pluck('cnt', 'submission_uuid');

        // Build rows with processed counts and discrepancy
        $rows = $bySubmission->map(function (array $s) use ($txnCounts, $tz) {
            $processed = (int) ($txnCounts[$s['submission_uuid']] ?? 0);
            $expected = (int) $s['declared_count'];
            $diff = $expected - $processed;
            $dateLocal = $s['occurred_at'] ? Carbon::parse($s['occurred_at'])->setTimezone($tz)->toDateString() : null;
            return array_merge($s, [
                'processed_count' => $processed,
                'discrepancy'     => $diff,
                'date'            => $dateLocal,
            ]);
        });

        if ($grouping === 'submission') {
            $this->renderPerSubmission($rows, $csvPath, $includeRejected);
        } else { // day
            $this->renderPerDay($rows, $csvPath);
        }

        return self::SUCCESS;
    }

    protected function renderPerSubmission(Collection $rows, ?string $csvPath, bool $includeRejected): void
    {
        $display = $rows->when(!$includeRejected, function ($c) {
            return $c->filter(fn ($r) => $r['final_status'] !== 'REJECTED');
        });

        $this->table(
            ['Date', 'Submission UUID', 'Tenant', 'Terminal', 'Status', 'Expected', 'Processed', 'Diff', 'Reason'],
            $display->map(function ($r) {
                return [
                    $r['date'],
                    $r['submission_uuid'],
                    $r['tenant_id'],
                    $r['terminal_id'],
                    $r['final_status'],
                    $r['declared_count'],
                    $r['processed_count'],
                    $r['discrepancy'],
                    $r['reason_code'] ?? '',
                ];
            })->all()
        );

        if ($csvPath) {
            $this->writeCsv($csvPath, [
                'date','submission_uuid','tenant_id','terminal_id','status','expected_count','processed_count','discrepancy','reason_code','reason_details_json'
            ], $rows->map(function ($r) use ($includeRejected) {
                if (!$includeRejected && $r['final_status'] === 'REJECTED') {
                    return null; // filtered
                }
                return [
                    $r['date'],
                    $r['submission_uuid'],
                    $r['tenant_id'],
                    $r['terminal_id'],
                    $r['final_status'],
                    $r['declared_count'],
                    $r['processed_count'],
                    $r['discrepancy'],
                    $r['reason_code'] ?? '',
                    $r['reason_details'] ? json_encode($r['reason_details']) : '',
                ];
            })->filter()->values()->all());
            $this->info("CSV written: {$csvPath}");
        }
    }

    protected function renderPerDay(Collection $rows, ?string $csvPath): void
    {
        $summary = $rows->groupBy('date')->map(function (Collection $g) {
            $received = $g->count();
            $completed = $g->where('final_status', 'COMPLETED')->count();
            $rejected = $g->where('final_status', 'REJECTED')->count();
            $expected = (int) $g->sum('declared_count');
            $processed = (int) $g->sum('processed_count');
            $diff = $expected - $processed;
            return [
                'date' => $g->first()['date'],
                'received' => $received,
                'completed' => $completed,
                'rejected' => $rejected,
                'expected_tx' => $expected,
                'processed_tx' => $processed,
                'discrepancy' => $diff,
            ];
        })->sortKeys();

        $this->table(
            ['Date', 'Submissions (Rcvd)', 'Completed', 'Rejected', 'Expected Tx', 'Processed Tx', 'Diff'],
            $summary->map(fn ($r) => [
                $r['date'], $r['received'], $r['completed'], $r['rejected'], $r['expected_tx'], $r['processed_tx'], $r['discrepancy']
            ])->values()->all()
        );

        if ($csvPath) {
            $this->writeCsv($csvPath, [
                'date','submissions_received','submissions_completed','submissions_rejected','expected_transactions','processed_transactions','discrepancy'
            ], $summary->values()->map(fn ($r) => [
                $r['date'], $r['received'], $r['completed'], $r['rejected'], $r['expected_tx'], $r['processed_tx'], $r['discrepancy']
            ])->all());
            $this->info("CSV written: {$csvPath}");
        }
    }

    protected function writeCsv(string $path, array $headers, array $rows): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = fopen($path, 'w');
        if ($fh === false) {
            $this->error("Failed to write CSV at {$path}");
            return;
        }
        fputcsv($fh, $headers);
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);
    }
}
