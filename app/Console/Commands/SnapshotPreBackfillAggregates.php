<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PreBackfillSnapshotRecord;
use App\Models\PreBackfillSnapshotRun;
use App\Services\Reports\SalesReportDataService;
use App\Services\Reports\SalesReportFilter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 002-backfill-transaction-taxes, Slice 15 (T073/T074) — see
 * specs/002-backfill-transaction-taxes/slice-15-pre-backfill-snapshot-brief.md.
 *
 * Captures the pre-backfill baseline for materiality (FR-009a/FR-012):
 * per-(tenant, reporting month) **rendered** aggregate totals via the actual
 * report path (App\Services\Reports\SalesReportDataService::getCmsrReportData()),
 * before any mutation. This slice is read-only against reporting and writes
 * only to its own two tables (pre_backfill_snapshot_runs/_records) — it never
 * computes materiality, never refreshes an aggregate, and never touches
 * transaction_taxes/transactions.
 *
 * Idempotency/resumability (Design decision 5 of the brief): the run-level
 * key is (snapshot_type, window_start, window_end, report_contract_version)
 * — both snapshot_type and report_contract_version are fixed class constants
 * on App\Models\PreBackfillSnapshotRun, never CLI-configurable here. A
 * completed run for that exact key refuses a bare re-invocation (zero report
 * calls, zero writes) unless --force is given, in which case a brand-new,
 * independent run row is created and the prior run/records are never
 * touched. A running/failed run for that exact key is resumed in place:
 * already-captured (tenant, month) pairs (per the unique constraint on
 * pre_backfill_snapshot_records) are skipped, and only the missing pairs are
 * attempted.
 *
 * Dry-run (no --apply, this feature's established default-safe convention)
 * resolves the tenant/month population and the run lookup, reports it, and
 * calls the report path zero times — the mechanism for that guarantee is
 * structural, not a flag check: the report service is only ever invoked
 * inside the --apply branch of handle().
 *
 * **Operational requirement (Architect drift-revalidation, Slice 15):** the
 * concurrency guard around the per-invocation critical section (see the
 * Cache::lock() usage in handle()) only provides real cross-process mutual
 * exclusion under a distributed-lock-capable CACHE_DRIVER (redis/database/
 * memcached/dynamodb). CACHE_DRIVER=file (single-host only) or =array
 * (in-process only, used by .env.testing) does NOT protect against two
 * genuinely separate PHP processes racing this command for the same window.
 * Confirm CACHE_DRIVER=redis (this repo's real-deployment default per
 * .env.example) before ever running this command for a real capture.
 */
class SnapshotPreBackfillAggregates extends Command
{
    protected $signature = 'transactions:snapshot-pre-backfill-aggregates
        {--from= : Window start (Y-m-d). Required.}
        {--to= : Window end, exclusive (Y-m-d). Required.}
        {--apply : Persist. Without this flag: preview only — list the tenant/month pairs that would be captured, which (if any) already have a record under an existing run for this window, and an estimated call count. Never calls the report path.}
        {--force : Required to start a new run when a completed run already exists for the identical (snapshot_type, window, report_contract_version) key.}
        {--throttle= : Milliseconds between each per-tenant/month report call.}
        {--json}';

    protected $description = 'Capture the pre-backfill rendered-aggregate baseline (FR-012) per (tenant, reporting month) via the real report path, before any tax backfill mutation';

    public function handle(SalesReportDataService $service): int
    {
        $validationError = $this->validateInput();

        if ($validationError !== null) {
            $this->error($validationError);

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');

        [$windowStart, $windowEnd] = $this->resolveWindow();

        // Finding 1 (Slice 15 review): resolveRunDecision() + createRun() is
        // an unlocked lookup-then-insert. Two concurrent invocations for the
        // identical (snapshot_type, window_start, window_end,
        // report_contract_version) key could both observe "no run exists"
        // and both create independent runs, each potentially reaching
        // `completed` — silently producing two baselines for the same
        // window with no DB constraint to catch it (FR-012 calls this
        // baseline "unrecoverable once the run begins"). The lock spans the
        // ENTIRE per-invocation flow — decision, capture, and finalize —
        // not just the decision+create step, which also guarantees no two
        // processes can ever concurrently insert pre_backfill_snapshot_records
        // rows for the same run (Finding 4).
        $lockKey = sprintf(
            'pre-backfill-snapshot:%s:%d:%d:%s',
            PreBackfillSnapshotRun::TYPE_PRE_BACKFILL_RENDERED_AGGREGATE,
            $windowStart->timestamp,
            $windowEnd->timestamp,
            PreBackfillSnapshotRun::REPORT_CONTRACT_VERSION_CMSR_V2
        );

        // TTL is a crash-safety net only (in case a process dies without
        // reaching the `finally` below) — the lock is always released
        // explicitly once this invocation finishes. Generous relative to
        // --throttle usage across a large tenant/month population so a
        // legitimately long-running capture never has its own lock expire
        // out from under it.
        $lock = Cache::lock($lockKey, 3600);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            $this->error(sprintf(
                'Another invocation appears to be in progress for this exact key (snapshot_type=%s, window %s to %s, report_contract_version=%s). Try again once it completes.',
                PreBackfillSnapshotRun::TYPE_PRE_BACKFILL_RENDERED_AGGREGATE,
                $windowStart->toDateTimeString(),
                $windowEnd->toDateTimeString(),
                PreBackfillSnapshotRun::REPORT_CONTRACT_VERSION_CMSR_V2
            ));

            return self::FAILURE;
        }

        try {
            $decision = $this->resolveRunDecision($windowStart, $windowEnd, $force);

            if ($decision['action'] === 'refuse') {
                $result = $this->buildRefusedResult($apply, $windowStart, $windowEnd, $decision['existing_run']);
                $this->render($result);

                // Dry-run is purely informational here — nothing was requested
                // to be written, so there's nothing to fail. --apply, on the
                // other hand, asked for a real capture and got nothing.
                return $apply ? self::FAILURE : self::SUCCESS;
            }

            $tenantIds = $this->enumerateTenantIds($windowStart, $windowEnd);
            $months = $this->enumerateReportingMonths($windowStart, $windowEnd);
            $pairs = $this->crossProduct($tenantIds, $months);

            $existingRun = $decision['existing_run'];
            $alreadyCaptured = $existingRun !== null
                ? $this->alreadyCapturedPairKeys($existingRun->id)
                : [];

            $pending = array_values(array_filter(
                $pairs,
                fn (array $pair) => ! isset($alreadyCaptured[$this->pairKey($pair)])
            ));

            if (! $apply) {
                $result = $this->buildPreviewResult(
                    $windowStart,
                    $windowEnd,
                    $decision,
                    $tenantIds,
                    $months,
                    $pairs,
                    $alreadyCaptured,
                    $force
                );
                $this->render($result);

                return self::SUCCESS;
            }

            $run = $decision['action'] === 'create'
                ? $this->createRun($windowStart, $windowEnd, count($tenantIds), count($months), $decision['forced'])
                : $existingRun;

            $throttleMs = $this->option('throttle') !== null ? (int) $this->option('throttle') : null;

            [$capturedCount, $failedCount, $failedPairs] = $this->capturePending($service, $run, $pending, $throttleMs);

            $run->status = $failedCount > 0 ? PreBackfillSnapshotRun::STATUS_FAILED : PreBackfillSnapshotRun::STATUS_COMPLETED;
            $run->completed_at = now();
            $run->save();

            $result = $this->buildApplyResult(
                $windowStart,
                $windowEnd,
                $run,
                $decision,
                $pairs,
                count($alreadyCaptured),
                $capturedCount,
                $failedCount,
                $failedPairs,
                $force
            );

            $this->render($result);

            return $run->status === PreBackfillSnapshotRun::STATUS_COMPLETED ? self::SUCCESS : self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    /**
     * Fail fast, before any DB access beyond option parsing.
     */
    protected function validateInput(): ?string
    {
        $from = $this->option('from');
        $to = $this->option('to');

        if ($from === null || $from === '') {
            return '--from is required.';
        }

        if ($to === null || $to === '') {
            return '--to is required.';
        }

        if (! $this->isValidDate((string) $from)) {
            return "Invalid --from value '{$from}': expected format Y-m-d.";
        }

        if (! $this->isValidDate((string) $to)) {
            return "Invalid --to value '{$to}': expected format Y-m-d.";
        }

        [$windowStart, $windowEnd] = $this->resolveWindow();

        if ($windowEnd->lte($windowStart)) {
            return "--from ({$from}) and --to ({$to}) resolve to an empty window — --to is exclusive and must be after --from.";
        }

        if ($this->option('throttle') !== null && ! $this->isPositiveInteger($this->option('throttle'))) {
            return "Invalid --throttle value '{$this->option('throttle')}': must be a positive integer.";
        }

        return null;
    }

    protected function isValidDate(string $value): bool
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    protected function isPositiveInteger(mixed $value): bool
    {
        return $value !== null && $value !== '' && ctype_digit((string) $value) && (int) $value > 0;
    }

    /**
     * `--to` is exclusive per the CLI contract — window_start/window_end are
     * stored and compared literally as given (start-of-day of --from,
     * start-of-day of --to), which is also the run-level idempotency key.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveWindow(): array
    {
        return [
            Carbon::createFromFormat('Y-m-d', (string) $this->option('from'))->startOfDay(),
            Carbon::createFromFormat('Y-m-d', (string) $this->option('to'))->startOfDay(),
        ];
    }

    /**
     * Design decision 5: look up an existing run matching all four
     * idempotency-key fields, and decide what this invocation should do.
     *
     * @return array{action: 'refuse'|'create'|'resume', existing_run: PreBackfillSnapshotRun|null, forced: bool}
     */
    protected function resolveRunDecision(Carbon $windowStart, Carbon $windowEnd, bool $force): array
    {
        // No DB-level uniqueness backs this tuple (see the runs migration's
        // docblock) — a --force invocation deliberately creates another row
        // sharing it. orderByDesc('id') makes the lookup deterministic in
        // that case: the most recently created run for this key always wins
        // for refusal/resume purposes, never an arbitrary row.
        $existing = PreBackfillSnapshotRun::query()
            ->where('snapshot_type', PreBackfillSnapshotRun::TYPE_PRE_BACKFILL_RENDERED_AGGREGATE)
            ->where('window_start', $windowStart)
            ->where('window_end', $windowEnd)
            ->where('report_contract_version', PreBackfillSnapshotRun::REPORT_CONTRACT_VERSION_CMSR_V2)
            ->orderByDesc('id')
            ->first();

        if ($existing === null) {
            return ['action' => 'create', 'existing_run' => null, 'forced' => false];
        }

        if ($existing->status === PreBackfillSnapshotRun::STATUS_COMPLETED) {
            if (! $force) {
                return ['action' => 'refuse', 'existing_run' => $existing, 'forced' => false];
            }

            // A new, independent run — the prior completed run and its
            // records are never touched, updated, or deleted.
            return ['action' => 'create', 'existing_run' => null, 'forced' => true];
        }

        // running or failed: resume in place, same run_id.
        return ['action' => 'resume', 'existing_run' => $existing, 'forced' => (bool) $existing->forced];
    }

    /**
     * Design decision 4: the actual affected tenant population for this
     * window, derived from `transactions` — not every `Tenant` row (no
     * active-date-range field exists on Tenant to filter by anyway).
     *
     * @return list<int>
     */
    protected function enumerateTenantIds(Carbon $windowStart, Carbon $windowEnd): array
    {
        return DB::table('transactions')
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<', $windowEnd)
            ->whereNotNull('tenant_id')
            ->distinct()
            ->pluck('tenant_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Design decision 4: every calendar (year, month) from --from's month
     * through (--to minus one day)'s month, inclusive.
     *
     * @return list<array{0: int, 1: int}>
     */
    protected function enumerateReportingMonths(Carbon $windowStart, Carbon $windowEnd): array
    {
        $lastTouchedDay = $windowEnd->copy()->subDay();

        $cursor = $windowStart->copy()->startOfMonth();
        $end = $lastTouchedDay->copy()->startOfMonth();

        $months = [];

        while ($cursor->lte($end)) {
            $months[] = [$cursor->year, $cursor->month];
            $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    /**
     * @param  list<int>  $tenantIds
     * @param  list<array{0: int, 1: int}>  $months
     * @return list<array{tenant_id: int, year: int, month: int}>
     */
    protected function crossProduct(array $tenantIds, array $months): array
    {
        $pairs = [];

        foreach ($tenantIds as $tenantId) {
            foreach ($months as [$year, $month]) {
                $pairs[] = ['tenant_id' => $tenantId, 'year' => $year, 'month' => $month];
            }
        }

        return $pairs;
    }

    protected function pairKey(array $pair): string
    {
        return "{$pair['tenant_id']}:{$pair['year']}:{$pair['month']}";
    }

    /**
     * @return array<string, true>
     */
    protected function alreadyCapturedPairKeys(int $runId): array
    {
        return PreBackfillSnapshotRecord::query()
            ->where('run_id', $runId)
            ->get(['tenant_id', 'reporting_year', 'reporting_month'])
            ->mapWithKeys(fn ($record) => ["{$record->tenant_id}:{$record->reporting_year}:{$record->reporting_month}" => true])
            ->all();
    }

    /**
     * Finding 3 (Slice 15 review): --force only ever matters for overriding
     * a completed run (Design decision 5) — a running/failed run is always
     * resumed regardless of the flag, which is correct per the brief. But an
     * operator who passes --force and gets a 'resume' decision anyway has no
     * way to tell "the flag had no effect because it doesn't apply here"
     * apart from a bug silently swallowing it. Surface it explicitly.
     */
    protected function forceNoEffectNote(bool $force, string $action): ?string
    {
        if (! $force || $action !== 'resume') {
            return null;
        }

        return '--force had no effect: an existing running/failed run was resumed instead.';
    }

    protected function createRun(Carbon $windowStart, Carbon $windowEnd, int $tenantCount, int $monthCount, bool $forced): PreBackfillSnapshotRun
    {
        return PreBackfillSnapshotRun::create([
            'snapshot_type' => PreBackfillSnapshotRun::TYPE_PRE_BACKFILL_RENDERED_AGGREGATE,
            'report_contract_version' => PreBackfillSnapshotRun::REPORT_CONTRACT_VERSION_CMSR_V2,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'status' => PreBackfillSnapshotRun::STATUS_RUNNING,
            'tenant_count' => $tenantCount,
            'month_count' => $monthCount,
            'forced' => $forced,
            'started_at' => now(),
        ]);
    }

    /**
     * Attempts every pending (tenant, month) pair, catching exceptions
     * per-pair (Design decision 5a) so one tenant's report call never aborts
     * the whole run. This is the ONLY place App\Services\Reports\SalesReportDataService::getCmsrReportData()
     * is ever called — structurally unreachable from the dry-run path.
     *
     * @param  list<array{tenant_id: int, year: int, month: int}>  $pending
     * @return array{0: int, 1: int, 2: list<array{tenant_id: int, year: int, month: int}>} [capturedCount, failedCount, failedPairs]
     */
    protected function capturePending(SalesReportDataService $service, PreBackfillSnapshotRun $run, array $pending, ?int $throttleMs): array
    {
        $captured = 0;
        $failed = 0;
        $failedPairs = [];
        $total = count($pending);

        foreach ($pending as $index => $pair) {
            try {
                $filter = SalesReportFilter::forTenantYearMonth($pair['tenant_id'], $pair['year'], $pair['month']);
                $reportResult = $service->getCmsrReportData($filter);

                PreBackfillSnapshotRecord::create([
                    'run_id' => $run->id,
                    'tenant_id' => $pair['tenant_id'],
                    'reporting_year' => $pair['year'],
                    'reporting_month' => $pair['month'],
                    'source' => $reportResult->source,
                    'rendered_result' => $reportResult->toArray(),
                    'captured_at' => now(),
                ]);

                $captured++;
            } catch (Throwable $e) {
                $failed++;

                // Finding 2 (Slice 15 review): capturePending() already
                // knows exactly which pairs failed in-memory — surface their
                // identities in the persisted result (buildApplyResult), not
                // just via Log::warning, so an operator can find them
                // without grepping logs.
                $failedPairs[] = [
                    'tenant_id' => $pair['tenant_id'],
                    'year' => $pair['year'],
                    'month' => $pair['month'],
                ];

                Log::warning('SnapshotPreBackfillAggregates: capture failed for one (tenant, month) pair — continuing with the rest.', [
                    'run_id' => $run->id,
                    'tenant_id' => $pair['tenant_id'],
                    'reporting_year' => $pair['year'],
                    'reporting_month' => $pair['month'],
                    'exception' => $e->getMessage(),
                ]);
            }

            if ($throttleMs !== null && $index < $total - 1) {
                usleep($throttleMs * 1000);
            }
        }

        return [$captured, $failed, $failedPairs];
    }

    /**
     * One result array drives both the human table and --json output — no
     * separate/duplicated formatting logic that could drift between them
     * (this feature's established one-result-object convention).
     */
    protected function buildRefusedResult(bool $apply, Carbon $windowStart, Carbon $windowEnd, PreBackfillSnapshotRun $existingRun): array
    {
        return [
            'mode' => $apply ? 'apply' : 'dry-run',
            'window' => ['start' => $windowStart->toDateTimeString(), 'end' => $windowEnd->toDateTimeString()],
            'snapshot_type' => PreBackfillSnapshotRun::TYPE_PRE_BACKFILL_RENDERED_AGGREGATE,
            'report_contract_version' => PreBackfillSnapshotRun::REPORT_CONTRACT_VERSION_CMSR_V2,
            'action' => 'refuse',
            'refused' => true,
            'existing_run' => [
                'id' => $existingRun->id,
                'status' => $existingRun->status,
                'completed_at' => $existingRun->completed_at?->toDateTimeString(),
            ],
            'run_id' => null,
            'status' => null,
            'forced' => false,
            'force_note' => null,
            'population' => null,
            'already_captured' => null,
            'pending' => null,
            'estimated_calls' => 0,
            'captured_this_run' => 0,
            'failed_this_run' => 0,
            'failed_pairs' => [],
        ];
    }

    protected function buildPreviewResult(
        Carbon $windowStart,
        Carbon $windowEnd,
        array $decision,
        array $tenantIds,
        array $months,
        array $pairs,
        array $alreadyCaptured,
        bool $force
    ): array {
        $existingRun = $decision['existing_run'];

        return [
            'mode' => 'dry-run',
            'window' => ['start' => $windowStart->toDateTimeString(), 'end' => $windowEnd->toDateTimeString()],
            'snapshot_type' => PreBackfillSnapshotRun::TYPE_PRE_BACKFILL_RENDERED_AGGREGATE,
            'report_contract_version' => PreBackfillSnapshotRun::REPORT_CONTRACT_VERSION_CMSR_V2,
            'action' => $decision['action'],
            'refused' => false,
            'existing_run' => $existingRun !== null ? [
                'id' => $existingRun->id,
                'status' => $existingRun->status,
                'completed_at' => $existingRun->completed_at?->toDateTimeString(),
            ] : null,
            'run_id' => $existingRun?->id,
            'status' => $existingRun?->status,
            'forced' => $decision['forced'],
            'force_note' => $this->forceNoEffectNote($force, $decision['action']),
            'population' => [
                'tenant_count' => count($tenantIds),
                'month_count' => count($months),
                'pair_count' => count($pairs),
            ],
            'already_captured' => count($alreadyCaptured),
            'pending' => count($pairs) - count($alreadyCaptured),
            'estimated_calls' => count($pairs) - count($alreadyCaptured),
            'captured_this_run' => 0,
            'failed_this_run' => 0,
            'failed_pairs' => [],
        ];
    }

    /**
     * @param  list<array{tenant_id: int, year: int, month: int}>  $failedPairs
     */
    protected function buildApplyResult(
        Carbon $windowStart,
        Carbon $windowEnd,
        PreBackfillSnapshotRun $run,
        array $decision,
        array $pairs,
        int $alreadyCapturedCount,
        int $capturedThisRun,
        int $failedThisRun,
        array $failedPairs,
        bool $force
    ): array {
        return [
            'mode' => 'apply',
            'window' => ['start' => $windowStart->toDateTimeString(), 'end' => $windowEnd->toDateTimeString()],
            'snapshot_type' => $run->snapshot_type,
            'report_contract_version' => $run->report_contract_version,
            'action' => $decision['action'],
            'refused' => false,
            'existing_run' => null,
            'run_id' => $run->id,
            'status' => $run->status,
            'forced' => (bool) $run->forced,
            'force_note' => $this->forceNoEffectNote($force, $decision['action']),
            'population' => [
                'tenant_count' => $run->tenant_count,
                'month_count' => $run->month_count,
                'pair_count' => count($pairs),
            ],
            'already_captured' => $alreadyCapturedCount,
            'pending' => count($pairs) - $alreadyCapturedCount,
            'estimated_calls' => count($pairs) - $alreadyCapturedCount,
            'captured_this_run' => $capturedThisRun,
            'failed_this_run' => $failedThisRun,
            'failed_pairs' => $failedPairs,
        ];
    }

    protected function render(array $result): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info(sprintf(
            'Pre-backfill snapshot (%s) — window %s to %s — action: %s',
            $result['mode'],
            $result['window']['start'],
            $result['window']['end'],
            $result['action']
        ));

        if ($result['refused']) {
            $this->error(sprintf(
                'REFUSED — a completed run (#%d, completed_at %s) already exists for this exact (snapshot_type, window, report_contract_version) key. Pass --force to create a new, independent run.',
                $result['existing_run']['id'],
                $result['existing_run']['completed_at'] ?? 'unknown'
            ));

            return;
        }

        if ($result['run_id'] !== null) {
            $this->line(sprintf('Run #%d — status: %s%s', $result['run_id'], $result['status'], $result['forced'] ? ' (forced)' : ''));
        }

        // Finding 3 (Slice 15 review): --force is a no-op when an existing
        // running/failed run is resumed instead of a new one being created
        // — correct per the brief, but worth surfacing so it doesn't read
        // as a bug silently swallowing the flag.
        if (! empty($result['force_note'])) {
            $this->line($result['force_note']);
        }

        if ($result['population'] !== null) {
            $this->table(
                ['Tenants', 'Months', 'Pairs', 'Already captured', 'Pending / estimated calls', 'Captured this run', 'Failed this run'],
                [[
                    $result['population']['tenant_count'],
                    $result['population']['month_count'],
                    $result['population']['pair_count'],
                    $result['already_captured'],
                    $result['pending'],
                    $result['captured_this_run'],
                    $result['failed_this_run'],
                ]]
            );
        }

        // Finding 2 (Slice 15 review): surface which (tenant, month) pairs
        // failed from persisted result data, not just Log::warning, so an
        // operator can find them without grepping logs.
        if (! empty($result['failed_pairs'])) {
            $this->line('Failed pairs (tenant_id, reporting year/month):');
            $this->table(
                ['Tenant ID', 'Year', 'Month'],
                array_map(
                    fn (array $pair) => [$pair['tenant_id'], $pair['year'], $pair['month']],
                    $result['failed_pairs']
                )
            );
        }

        if ($result['mode'] === 'dry-run') {
            $this->line('Dry run — the report path was never called and nothing was written.');
        }
    }
}
