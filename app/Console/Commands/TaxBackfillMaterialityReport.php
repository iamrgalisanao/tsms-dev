<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PreBackfillSnapshotRecord;
use App\Models\PreBackfillSnapshotRun;
use App\Models\TaxBackfillMaterialityRecord;
use App\Models\TaxBackfillMaterialityRun;
use App\Services\Reports\SalesReportDataService;
use App\Services\Reports\SalesReportFilter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 002-backfill-transaction-taxes, Slice 19 (T047 / Command 3) — see
 * specs/002-backfill-transaction-taxes/slice-19-materiality-brief.md.
 *
 * Compares Slice 15's pinned pre-backfill rendered snapshot ("before")
 * against a freshly captured, equally-pinned post-refresh render ("after"),
 * per (tenant, reporting month), and reports which pairs cross the FR-009a
 * materiality threshold. Read-only against `transactions`/reporting; writes
 * only its own two tables. Never mutates transactions, tax rows, summaries,
 * or Slice 15's snapshot tables.
 *
 * Threshold evaluation is a read-time concern, not baked into a persisted
 * flag (architectural constraint confirmed for this slice): --apply
 * captures and permanently pins the before/after/delta evidence exactly
 * once per snapshot run (SC-006 reproducibility); the materiality flag
 * itself is recomputed on every invocation, including read-only re-displays
 * of an already-completed run, from whatever --threshold-amount/
 * --threshold-percent that invocation was given — so finance can retune
 * thresholds later without re-rendering anything.
 *
 * Dry-run never calls the report path (consistency with every other command
 * in this feature) — but unlike a first-time preview, re-invoking against a
 * snapshot run that already has a *completed* materiality run is not an
 * error and is not preview-only: it displays the full persisted comparison
 * with freshly evaluated threshold flags, since that display is a pure DB
 * read, not a report-path call.
 *
 * **Operational requirement (same as Slice 15):** the Cache::lock() below
 * only provides real cross-process mutual exclusion under a distributed-
 * lock-capable CACHE_DRIVER (redis/database/memcached/dynamodb). file
 * (single-host only) or array (in-process only) does NOT protect against
 * two genuinely separate PHP processes racing this command.
 *
 * **Known, accepted, narrow race (Architect drift-revalidation, Slice 19):**
 * when --snapshot-run is omitted, resolveSnapshotRun() picks the most
 * recently completed pre_backfill_snapshot_runs row *before* the
 * Cache::lock() below is acquired. Two concurrent default-resolution
 * invocations could therefore legitimately resolve to two different
 * snapshot runs if a new Slice 15 snapshot completes in between — each then
 * safely completes its own independent, correctly-labeled materiality run
 * (snapshot_run_id is always in the output), never a corrupted or
 * double-written row. Slice 15 snapshots are deliberately rare/manual
 * events, not continuously refreshed, so this window is narrow in practice;
 * pass --snapshot-run explicitly to eliminate it entirely.
 */
class TaxBackfillMaterialityReport extends Command
{
    protected $signature = 'transactions:tax-backfill-materiality
        {--snapshot-run= : pre_backfill_snapshot_runs.id to use as the before baseline. Defaults to the most recently completed run for the standard key.}
        {--threshold-amount=500 : Absolute other_tax delta (PHP) that triggers materiality.}
        {--threshold-percent=1 : Percent other_tax delta that triggers materiality.}
        {--apply : Capture the after-render and persist deltas. Without this flag: preview only if nothing has been captured yet, or a full read-only display (with freshly evaluated thresholds) if a completed run already exists.}
        {--force : Required to start a new materiality run when a completed one already exists for the identical snapshot run.}
        {--json}';

    protected $description = 'Compare pre-backfill and post-refresh rendered other_tax totals per (tenant, reporting month) and flag materiality (FR-009a)';

    public function handle(SalesReportDataService $service): int
    {
        $validationError = $this->validateInput();

        if ($validationError !== null) {
            $this->error($validationError);

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');
        $thresholdAmount = (float) $this->option('threshold-amount');
        $thresholdPercent = (float) $this->option('threshold-percent');

        $snapshotRun = $this->resolveSnapshotRun();

        if ($snapshotRun === null) {
            $this->error($this->option('snapshot-run') !== null
                ? "Snapshot run #{$this->option('snapshot-run')} was not found."
                : 'No completed pre-backfill snapshot run exists. Run transactions:snapshot-pre-backfill-aggregates --apply first — there is no legitimate before value without one.');

            return self::FAILURE;
        }

        if ($snapshotRun->status !== PreBackfillSnapshotRun::STATUS_COMPLETED) {
            $this->error("Snapshot run #{$snapshotRun->id} is not completed (status: {$snapshotRun->status}). Materiality requires a completed before-baseline.");

            return self::FAILURE;
        }

        $lockKey = sprintf(
            'tax-backfill-materiality:%d:%s',
            $snapshotRun->id,
            TaxBackfillMaterialityRun::REPORT_CONTRACT_VERSION_CMSR_V2
        );

        $lock = Cache::lock($lockKey, 3600);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            $this->error("Another invocation appears to be in progress for snapshot run #{$snapshotRun->id}. Try again once it completes.");

            return self::FAILURE;
        }

        try {
            $decision = $this->resolveMaterialityRunDecision($snapshotRun->id, $force);

            if ($decision['action'] === 'refuse') {
                if ($apply) {
                    $this->error("REFUSED — a completed materiality run (#{$decision['existing_run']->id}) already exists for snapshot run #{$snapshotRun->id}. Pass --force to create a new, independent run.");

                    return self::FAILURE;
                }

                $result = $this->buildDisplayResult($decision['existing_run'], $thresholdAmount, $thresholdPercent, 'read');
                $this->render($result);

                return self::SUCCESS;
            }

            $population = PreBackfillSnapshotRecord::query()
                ->where('run_id', $snapshotRun->id)
                ->get();

            if ($population->isEmpty()) {
                $this->error("Snapshot run #{$snapshotRun->id} has no captured (tenant, month) pairs. Nothing to compare.");

                return self::FAILURE;
            }

            $beforeByPairKey = $population->mapWithKeys(
                fn (PreBackfillSnapshotRecord $r) => [$this->pairKey($r->tenant_id, $r->reporting_year, $r->reporting_month) => $r]
            );

            $existingRun = $decision['existing_run'];
            $alreadyCaptured = $existingRun !== null
                ? TaxBackfillMaterialityRecord::query()
                    ->where('run_id', $existingRun->id)
                    ->get(['tenant_id', 'reporting_year', 'reporting_month'])
                    ->mapWithKeys(fn ($r) => [$this->pairKey($r->tenant_id, $r->reporting_year, $r->reporting_month) => true])
                : collect();

            $pending = $beforeByPairKey->keys()
                ->reject(fn (string $key) => $alreadyCaptured->has($key))
                ->values();

            if (! $apply) {
                if ($existingRun !== null) {
                    // action === 'resume': something was already captured —
                    // display it as-is (partial), never call the report path.
                    $result = $this->buildDisplayResult($existingRun, $thresholdAmount, $thresholdPercent, 'dry-run-partial');
                    $result['pending'] = $pending->count();
                    $this->render($result);

                    return self::SUCCESS;
                }

                $result = [
                    'mode' => 'dry-run',
                    'snapshot_run_id' => $snapshotRun->id,
                    'action' => $decision['action'],
                    'threshold_amount' => $thresholdAmount,
                    'threshold_percent' => $thresholdPercent,
                    'population' => $population->count(),
                    'pending' => $pending->count(),
                    'note' => 'No materiality capture exists yet for this snapshot run. Nothing was rendered or written. Pass --apply to capture the after-render and compute deltas.',
                ];
                $this->render($result);

                return self::SUCCESS;
            }

            $run = $decision['action'] === 'create'
                ? $this->createRun($snapshotRun->id, $population, $decision['forced'])
                : $existingRun;

            [$capturedCount, $failedCount, $failedPairs] = $this->capturePending(
                $service,
                $run,
                $pending,
                $beforeByPairKey,
                $snapshotRun->report_contract_version
            );

            $run->status = $failedCount > 0 ? TaxBackfillMaterialityRun::STATUS_FAILED : TaxBackfillMaterialityRun::STATUS_COMPLETED;
            $run->completed_at = now();
            $run->save();

            $result = $this->buildDisplayResult($run, $thresholdAmount, $thresholdPercent, 'apply');
            $result['capture_stats'] = [
                'captured_this_run' => $capturedCount,
                'failed_this_run' => $failedCount,
                'failed_pairs' => $failedPairs,
            ];
            $this->render($result);

            return $run->status === TaxBackfillMaterialityRun::STATUS_COMPLETED ? self::SUCCESS : self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    protected function validateInput(): ?string
    {
        $snapshotRunOption = $this->option('snapshot-run');
        if ($snapshotRunOption !== null && $snapshotRunOption !== '' && ! $this->isPositiveInteger($snapshotRunOption)) {
            return "Invalid --snapshot-run value '{$snapshotRunOption}': must be a positive integer.";
        }

        if (! $this->isNonNegativeNumber($this->option('threshold-amount'))) {
            return "Invalid --threshold-amount value '{$this->option('threshold-amount')}': must be a non-negative number.";
        }

        if (! $this->isNonNegativeNumber($this->option('threshold-percent'))) {
            return "Invalid --threshold-percent value '{$this->option('threshold-percent')}': must be a non-negative number.";
        }

        return null;
    }

    protected function isPositiveInteger(mixed $value): bool
    {
        return $value !== null && $value !== '' && ctype_digit((string) $value) && (int) $value > 0;
    }

    protected function isNonNegativeNumber(mixed $value): bool
    {
        return $value !== null && $value !== '' && is_numeric($value) && (float) $value >= 0;
    }

    protected function resolveSnapshotRun(): ?PreBackfillSnapshotRun
    {
        $explicit = $this->option('snapshot-run');

        if ($explicit !== null && $explicit !== '') {
            return PreBackfillSnapshotRun::find((int) $explicit);
        }

        return PreBackfillSnapshotRun::query()
            ->where('snapshot_type', PreBackfillSnapshotRun::TYPE_PRE_BACKFILL_RENDERED_AGGREGATE)
            ->where('report_contract_version', PreBackfillSnapshotRun::REPORT_CONTRACT_VERSION_CMSR_V2)
            ->where('status', PreBackfillSnapshotRun::STATUS_COMPLETED)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{action: 'refuse'|'create'|'resume', existing_run: TaxBackfillMaterialityRun|null, forced: bool}
     */
    protected function resolveMaterialityRunDecision(int $snapshotRunId, bool $force): array
    {
        $existing = TaxBackfillMaterialityRun::query()
            ->where('snapshot_run_id', $snapshotRunId)
            ->where('report_contract_version', TaxBackfillMaterialityRun::REPORT_CONTRACT_VERSION_CMSR_V2)
            ->orderByDesc('id')
            ->first();

        if ($existing === null) {
            return ['action' => 'create', 'existing_run' => null, 'forced' => false];
        }

        if ($existing->status === TaxBackfillMaterialityRun::STATUS_COMPLETED) {
            if (! $force) {
                return ['action' => 'refuse', 'existing_run' => $existing, 'forced' => false];
            }

            return ['action' => 'create', 'existing_run' => null, 'forced' => true];
        }

        return ['action' => 'resume', 'existing_run' => $existing, 'forced' => (bool) $existing->forced];
    }

    protected function pairKey(int $tenantId, int $year, int $month): string
    {
        return "{$tenantId}:{$year}:{$month}";
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PreBackfillSnapshotRecord>  $population
     */
    protected function createRun(int $snapshotRunId, $population, bool $forced): TaxBackfillMaterialityRun
    {
        return TaxBackfillMaterialityRun::create([
            'snapshot_run_id' => $snapshotRunId,
            'report_contract_version' => TaxBackfillMaterialityRun::REPORT_CONTRACT_VERSION_CMSR_V2,
            'status' => TaxBackfillMaterialityRun::STATUS_RUNNING,
            'tenant_count' => $population->pluck('tenant_id')->unique()->count(),
            'month_count' => $population->map(fn (PreBackfillSnapshotRecord $r) => "{$r->reporting_year}-{$r->reporting_month}")->unique()->count(),
            'forced' => $forced,
            'started_at' => now(),
        ]);
    }

    /**
     * Attempts every pending (tenant, month) pair, catching exceptions
     * per-pair so one tenant's report call never aborts the whole run —
     * mirrors SnapshotPreBackfillAggregates::capturePending(). A source
     * mismatch (FR-012a) or report-contract mismatch is NOT an exception:
     * each is a legitimate, distinctly recorded outcome, not a failure — one
     * tenant's source flip or a v1/v2 evidence boundary must not block
     * visibility into every other tenant's materiality determination.
     *
     * @param  \Illuminate\Support\Collection<string, bool>  $pending
     * @param  \Illuminate\Support\Collection<string, PreBackfillSnapshotRecord>  $beforeByPairKey
     * @return array{0: int, 1: int, 2: list<array{tenant_id: int, year: int, month: int}>}
     */
    protected function capturePending(
        SalesReportDataService $service,
        TaxBackfillMaterialityRun $run,
        $pending,
        $beforeByPairKey,
        string $beforeReportContractVersion
    ): array {
        $captured = 0;
        $failed = 0;
        $failedPairs = [];

        foreach ($pending as $pairKey) {
            /** @var PreBackfillSnapshotRecord $before */
            $before = $beforeByPairKey->get($pairKey);
            [$tenantId, $year, $month] = array_map('intval', explode(':', $pairKey));

            try {
                $filter = SalesReportFilter::forTenantYearMonth($tenantId, $year, $month);
                $afterResult = $service->getCmsrReportData($filter);

                if ($beforeReportContractVersion !== PreBackfillSnapshotRun::REPORT_CONTRACT_VERSION_CMSR_V2) {
                    TaxBackfillMaterialityRecord::create([
                        'run_id' => $run->id,
                        'tenant_id' => $tenantId,
                        'reporting_year' => $year,
                        'reporting_month' => $month,
                        'before_source' => $before->source,
                        'after_source' => $afterResult->source,
                        'after_rendered_result' => $afterResult->toArray(),
                        'comparison_status' => TaxBackfillMaterialityRecord::COMPARISON_STATUS_CONTRACT_MISMATCH,
                        'other_tax_before' => null,
                        'other_tax_after' => null,
                        'other_tax_delta_amount' => null,
                        'other_tax_delta_percent' => null,
                        'captured_at' => now(),
                    ]);

                    $captured++;

                    continue;
                }

                if ($afterResult->source !== $before->source) {
                    TaxBackfillMaterialityRecord::create([
                        'run_id' => $run->id,
                        'tenant_id' => $tenantId,
                        'reporting_year' => $year,
                        'reporting_month' => $month,
                        'before_source' => $before->source,
                        'after_source' => $afterResult->source,
                        'after_rendered_result' => $afterResult->toArray(),
                        'comparison_status' => TaxBackfillMaterialityRecord::COMPARISON_STATUS_SOURCE_MISMATCH,
                        'other_tax_before' => null,
                        'other_tax_after' => null,
                        'other_tax_delta_amount' => null,
                        'other_tax_delta_percent' => null,
                        'captured_at' => now(),
                    ]);

                    $captured++;

                    continue;
                }

                // No ?? fallback here, deliberately: a rendered result missing
                // its own 'other_tax' key is malformed data, not a legitimate
                // zero — surfacing it as a failed pair (caught below) is safer
                // than silently computing a confidently-wrong delta against 0.
                $beforeOtherTax = $this->extractOtherTax($before->rendered_result['totals'] ?? []);
                $afterOtherTax = $this->extractOtherTax($afterResult->totals);

                [$deltaAmount, $deltaPercent] = $this->computeDelta($beforeOtherTax, $afterOtherTax);

                TaxBackfillMaterialityRecord::create([
                    'run_id' => $run->id,
                    'tenant_id' => $tenantId,
                    'reporting_year' => $year,
                    'reporting_month' => $month,
                    'before_source' => $before->source,
                    'after_source' => $afterResult->source,
                    'after_rendered_result' => $afterResult->toArray(),
                    'comparison_status' => TaxBackfillMaterialityRecord::COMPARISON_STATUS_COMPARED,
                    'other_tax_before' => $beforeOtherTax,
                    'other_tax_after' => $afterOtherTax,
                    'other_tax_delta_amount' => $deltaAmount,
                    'other_tax_delta_percent' => $deltaPercent,
                    'captured_at' => now(),
                ]);

                $captured++;
            } catch (Throwable $e) {
                $failed++;
                $failedPairs[] = ['tenant_id' => $tenantId, 'year' => $year, 'month' => $month];

                Log::warning('TaxBackfillMaterialityReport: capture failed for one (tenant, month) pair — continuing with the rest.', [
                    'run_id' => $run->id,
                    'tenant_id' => $tenantId,
                    'reporting_year' => $year,
                    'reporting_month' => $month,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return [$captured, $failed, $failedPairs];
    }

    protected function extractOtherTax(array $totals): float
    {
        if (! array_key_exists('other_tax', $totals)) {
            throw new \RuntimeException('Rendered result totals is missing the other_tax key — malformed report result.');
        }

        return (float) $totals['other_tax'];
    }

    /**
     * @return array{0: ?float, 1: ?float}
     */
    protected function computeDelta(float $before, float $after): array
    {
        $deltaAmount = round($after - $before, 2);
        $deltaPercent = $before !== 0.0 ? round(($deltaAmount / $before) * 100, 4) : null;

        return [$deltaAmount, $deltaPercent];
    }

    protected function isMaterial(?float $deltaAmount, ?float $deltaPercent, float $thresholdAmount, float $thresholdPercent): bool
    {
        if ($deltaAmount === null) {
            return false;
        }

        if (abs($deltaAmount) >= $thresholdAmount) {
            return true;
        }

        return $deltaPercent !== null && abs($deltaPercent) >= $thresholdPercent;
    }

    /**
     * One result array drives both the human table and --json output.
     * Reads persisted records fresh and evaluates the materiality flag at
     * call time against $thresholdAmount/$thresholdPercent — this is the
     * only place that flag is ever computed; it is never stored.
     */
    protected function buildDisplayResult(TaxBackfillMaterialityRun $run, float $thresholdAmount, float $thresholdPercent, string $mode): array
    {
        $records = TaxBackfillMaterialityRecord::query()
            ->where('run_id', $run->id)
            ->orderBy('tenant_id')
            ->orderBy('reporting_year')
            ->orderBy('reporting_month')
            ->get();

        $rows = [];
        $comparedCount = 0;
        $sourceMismatchCount = 0;
        $contractMismatchCount = 0;
        $flaggedCount = 0;
        $totalBefore = 0.0;
        $totalAfter = 0.0;

        foreach ($records as $record) {
            $isCompared = $record->comparison_status === TaxBackfillMaterialityRecord::COMPARISON_STATUS_COMPARED;
            $flag = $isCompared && $this->isMaterial($record->other_tax_delta_amount, $record->other_tax_delta_percent, $thresholdAmount, $thresholdPercent);

            if ($isCompared) {
                $comparedCount++;
                $totalBefore += $record->other_tax_before;
                $totalAfter += $record->other_tax_after;

                if ($flag) {
                    $flaggedCount++;
                }
            } elseif ($record->comparison_status === TaxBackfillMaterialityRecord::COMPARISON_STATUS_CONTRACT_MISMATCH) {
                $contractMismatchCount++;
            } else {
                $sourceMismatchCount++;
            }

            $rows[] = [
                'tenant_id' => $record->tenant_id,
                'reporting_year' => $record->reporting_year,
                'reporting_month' => $record->reporting_month,
                'before_source' => $record->before_source,
                'after_source' => $record->after_source,
                'comparison_status' => $record->comparison_status,
                'other_tax_before' => $record->other_tax_before,
                'other_tax_after' => $record->other_tax_after,
                'other_tax_delta_amount' => $record->other_tax_delta_amount,
                'other_tax_delta_percent' => $record->other_tax_delta_percent,
                'materiality_flag' => $flag,
            ];
        }

        return [
            'mode' => $mode,
            'snapshot_run_id' => $run->snapshot_run_id,
            'materiality_run_id' => $run->id,
            'materiality_run_status' => $run->status,
            'threshold_amount' => $thresholdAmount,
            'threshold_percent' => $thresholdPercent,
            'tax_exempt_contamination_note' => 'other_tax_before/other_tax_after individually include a boolean-summed transactions.tax_exempt contribution (FR-016, a pre-existing defect out of scope for this feature) and must not be read as clean standalone currency figures. The delta between them is unaffected, since this feature never modifies transactions.tax_exempt.',
            'records' => $rows,
            'summary' => [
                'population' => $records->count(),
                'compared' => $comparedCount,
                'source_mismatch' => $sourceMismatchCount,
                'contract_mismatch' => $contractMismatchCount,
                'flagged' => $flaggedCount,
                'total_other_tax_before' => round($totalBefore, 2),
                'total_other_tax_after' => round($totalAfter, 2),
                'total_other_tax_delta' => round($totalAfter - $totalBefore, 2),
            ],
            'capture_stats' => null,
        ];
    }

    protected function render(array $result): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info(sprintf('Materiality report — mode: %s', $result['mode']));

        if (isset($result['note'])) {
            $this->line($result['note']);
            $this->line(sprintf('Snapshot run #%d — population: %d, pending: %d', $result['snapshot_run_id'], $result['population'], $result['pending']));

            return;
        }

        $this->line(sprintf(
            'Snapshot run #%d — materiality run #%d (status: %s)',
            $result['snapshot_run_id'],
            $result['materiality_run_id'],
            $result['materiality_run_status']
        ));
        $this->line($result['tax_exempt_contamination_note']);

        $summary = $result['summary'];
        $this->table(
            ['Population', 'Compared', 'Source mismatch', 'Contract mismatch', 'Flagged', 'Total before', 'Total after', 'Total delta'],
            [[
                $summary['population'],
                $summary['compared'],
                $summary['source_mismatch'],
                $summary['contract_mismatch'],
                $summary['flagged'],
                $summary['total_other_tax_before'],
                $summary['total_other_tax_after'],
                $summary['total_other_tax_delta'],
            ]]
        );

        if (! empty($result['records'])) {
            $this->table(
                ['Tenant', 'Year', 'Month', 'Status', 'Before', 'After', 'Delta', 'Delta %', 'Flagged'],
                array_map(
                    fn (array $r) => [
                        $r['tenant_id'],
                        $r['reporting_year'],
                        $r['reporting_month'],
                        $r['comparison_status'],
                        $r['other_tax_before'],
                        $r['other_tax_after'],
                        $r['other_tax_delta_amount'],
                        $r['other_tax_delta_percent'],
                        $r['materiality_flag'] ? 'yes' : 'no',
                    ],
                    $result['records']
                )
            );
        }

        if (! empty($result['capture_stats']['failed_pairs'])) {
            $this->line('Failed pairs (tenant_id, reporting year/month):');
            $this->table(
                ['Tenant ID', 'Year', 'Month'],
                array_map(
                    fn (array $pair) => [$pair['tenant_id'], $pair['year'], $pair['month']],
                    $result['capture_stats']['failed_pairs']
                )
            );
        }
    }
}
