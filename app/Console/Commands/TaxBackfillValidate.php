<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 002-backfill-transaction-taxes, Slice 21 (T058-T061) — see
 * specs/002-backfill-transaction-taxes/slice-21-post-run-validation-brief.md.
 *
 * Four read-only post-run validation checks, bundled into one command
 * mirroring T076's (TaxBackfillReadinessVerdict) one-result-object,
 * multiple-check-blocks pattern: coverage by date (T058), coverage by
 * tenant (T059), tax-type distribution skew (T060), and VAT reconciliation
 * against transactions.vat_amount (T061, research.md R3's cross-check
 * applied in aggregate).
 *
 * T058/T059 are exact-match checks (zero tolerance) — a transaction either
 * has its reconstructed rows or it doesn't, so "coverage" is resolvable
 * precisely once quarantined transactions are excluded. T060 is the one
 * genuinely approximate, policy-driven check (business mix legitimately
 * varies between periods) and is WARN-only, never FAIL. T061 reuses
 * FR-009a's PHP 500/1% materiality threshold verbatim rather than inventing
 * a new number, satisfying SC-003's "stated, written tolerance" requirement
 * for aggregate-level exactness.
 *
 * No --apply flag, no persistence — every invocation recomputes fresh from
 * `transactions`/`transaction_taxes`/`tax_backfill_records`, exactly like
 * T076. Running it twice with the same inputs produces the same output,
 * with zero database writes either time.
 */
class TaxBackfillValidate extends Command
{
    protected $signature = 'transactions:tax-backfill-validate
        {--from= : Window start (Y-m-d). Required.}
        {--to= : Window end, exclusive (Y-m-d). Required.}
        {--tenant= : Optional tenant id, narrows all four checks to one tenant.}
        {--tax-type-skew-band=5 : T060 percentage-point band. A tax type\'s window-vs-reference share differing by more than this triggers a WARN.}
        {--tax-type-skew-floor=30 : T060 minimum row count required in BOTH periods before a type\'s skew is evaluated at all.}
        {--json}';

    protected $description = 'Read-only post-run validation: coverage by date/tenant, tax-type distribution skew, VAT reconciliation (T058-T061)';

    /**
     * The T060 reference period is fixed, not operator-configurable — per
     * the approved slice-21 brief, which reuses "the same oracle period R4
     * already uses" rather than an adjustable window. research.md R4 dates
     * the fix at "2026-08-10 ~10:00" with genuine imprecision about the
     * exact cutover instant. Matching VerifyTaxReconstruction::DEFAULT_FROM's
     * reasoning exactly: default to the first unambiguously post-fix
     * calendar day rather than risk straddling the boundary. Open-ended (no
     * upper bound) — the reference period runs from this instant to now.
     */
    protected const REFERENCE_FROM = '2026-08-11 00:00:00';

    /**
     * Mirrors TaxReconstructionService::applyLastWinsAliasLogic()'s VAT
     * alias set exactly (app/Services/Backfill/TaxReconstructionService.php)
     * so T061's reconciliation compares against the same rows the live
     * write path itself would have matched to `transactions.vat_amount`.
     */
    protected const VAT_TAX_TYPE_ALIASES = ['VAT', 'VAT_AMOUNT'];

    public function handle(): int
    {
        $validationError = $this->validateInput();

        if ($validationError !== null) {
            $this->error($validationError);

            return self::FAILURE;
        }

        [$windowStart, $windowEnd] = $this->resolveWindow();
        $tenantId = $this->positiveIntOption('tenant');
        $skewBand = (float) $this->option('tax-type-skew-band');
        $skewFloor = (int) $this->option('tax-type-skew-floor');
        [$referenceStart, $referenceEnd] = $this->resolveReferenceWindow();

        $checks = [
            'coverage_by_date' => $this->checkCoverageByDate($windowStart, $windowEnd, $tenantId),
            'coverage_by_tenant' => $this->checkCoverageByTenant($windowStart, $windowEnd, $tenantId),
            'tax_type_distribution' => $this->checkTaxTypeDistribution(
                $windowStart, $windowEnd, $referenceStart, $referenceEnd, $tenantId, $skewBand, $skewFloor
            ),
            'vat_reconciliation' => $this->checkVatReconciliation($windowStart, $windowEnd, $tenantId),
        ];

        $overallStatus = $this->overallStatus($checks);

        $result = [
            'mode' => 'read',
            'window' => ['start' => $windowStart->toDateTimeString(), 'end' => $windowEnd->toDateTimeString()],
            'tenant_id' => $tenantId,
            'overall_status' => $overallStatus,
            'checks' => $checks,
        ];

        $this->render($result);

        return $overallStatus === 'fail' ? self::FAILURE : self::SUCCESS;
    }

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

        if ($windowEnd->lt($windowStart)) {
            return "--from ({$from}) and --to ({$to}) resolve to an empty window — --to is exclusive and must be after --from.";
        }

        $tenant = $this->option('tenant');
        if ($tenant !== null && $tenant !== '' && ! $this->isPositiveInteger($tenant)) {
            return "Invalid --tenant value '{$tenant}': must be a positive integer.";
        }

        if (! is_numeric($this->option('tax-type-skew-band')) || (float) $this->option('tax-type-skew-band') <= 0) {
            return "Invalid --tax-type-skew-band value '{$this->option('tax-type-skew-band')}': must be a positive number.";
        }

        if (! $this->isPositiveInteger($this->option('tax-type-skew-floor'))) {
            return "Invalid --tax-type-skew-floor value '{$this->option('tax-type-skew-floor')}': must be a positive integer.";
        }

        return null;
    }

    protected function isPositiveInteger(mixed $value): bool
    {
        return ctype_digit((string) $value) && (int) $value > 0;
    }

    protected function isValidDate(string $value): bool
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    protected function positiveIntOption(string $name): ?int
    {
        $value = $this->option($name);

        return ($value !== null && $value !== '') ? (int) $value : null;
    }

    /**
     * --to is exclusive, but every check below queries with `whereBetween`,
     * which SQL evaluates inclusively on both ends — so the resolved end
     * must be one second before --to's own midnight, matching
     * BackfillTransactionTaxes::resolveWindow()'s established convention
     * for this exact pitfall elsewhere in this feature.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveWindow(): array
    {
        return [
            Carbon::createFromFormat('Y-m-d', (string) $this->option('from'))->startOfDay(),
            Carbon::createFromFormat('Y-m-d', (string) $this->option('to'))->startOfDay()->subSecond(),
        ];
    }

    /**
     * @return array{0: Carbon, 1: null}
     */
    protected function resolveReferenceWindow(): array
    {
        return [Carbon::parse(self::REFERENCE_FROM), null];
    }

    /**
     * T058: for each day with any transactions, with_tax_count MUST equal
     * total_count minus quarantined_count exactly. Any day where a
     * non-quarantined transaction is missing tax rows, or a quarantined
     * transaction unexpectedly has rows, fails.
     */
    protected function checkCoverageByDate(Carbon $from, Carbon $to, ?int $tenantId): array
    {
        $totalByDay = DB::table('transactions')
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->whereBetween('created_at', [$from, $to])
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->groupBy('day')
            ->pluck('total', 'day');

        $withTaxByDay = DB::table('transactions')
            ->join('transaction_taxes', 'transaction_taxes.transaction_pk', '=', 'transactions.id')
            ->selectRaw('DATE(transactions.created_at) as day, COUNT(DISTINCT transactions.id) as with_tax')
            ->whereBetween('transactions.created_at', [$from, $to])
            ->when($tenantId !== null, fn ($q) => $q->where('transactions.tenant_id', $tenantId))
            ->groupBy('day')
            ->pluck('with_tax', 'day');

        $quarantinedByDay = DB::table('transactions')
            ->join('tax_backfill_records', 'tax_backfill_records.transaction_pk', '=', 'transactions.id')
            ->where('tax_backfill_records.outcome', 'quarantined')
            ->selectRaw('DATE(transactions.created_at) as day, COUNT(DISTINCT transactions.id) as quarantined')
            ->whereBetween('transactions.created_at', [$from, $to])
            ->when($tenantId !== null, fn ($q) => $q->where('transactions.tenant_id', $tenantId))
            ->groupBy('day')
            ->pluck('quarantined', 'day');

        $failingDays = [];
        $daysChecked = 0;

        foreach ($totalByDay as $day => $total) {
            $daysChecked++;
            $withTax = (int) ($withTaxByDay[$day] ?? 0);
            $quarantined = (int) ($quarantinedByDay[$day] ?? 0);
            $expected = (int) $total - $quarantined;

            if ($withTax !== $expected) {
                $failingDays[] = [
                    'day' => $day,
                    'total' => (int) $total,
                    'with_tax' => $withTax,
                    'quarantined' => $quarantined,
                    'expected_with_tax' => $expected,
                ];
            }
        }

        return [
            'status' => empty($failingDays) ? 'pass' : 'fail',
            'reason' => empty($failingDays)
                ? "All {$daysChecked} day(s) with transactions have exact coverage (with_tax == total - quarantined)."
                : count($failingDays).' of '.$daysChecked.' day(s) have a coverage mismatch.',
            'days_checked' => $daysChecked,
            'failing_days' => $failingDays,
        ];
    }

    /**
     * T059: every affected tenant MUST have at least one backfilled
     * transaction, unless every one of that tenant's affected transactions
     * is quarantined (expected_zero) — verified against the quarantine
     * list, not just asserted.
     */
    protected function checkCoverageByTenant(Carbon $from, Carbon $to, ?int $tenantId): array
    {
        $totalByTenant = DB::table('transactions')
            ->selectRaw('tenant_id, COUNT(*) as total')
            ->whereBetween('created_at', [$from, $to])
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->groupBy('tenant_id')
            ->pluck('total', 'tenant_id');

        $withTaxByTenant = DB::table('transactions')
            ->join('transaction_taxes', 'transaction_taxes.transaction_pk', '=', 'transactions.id')
            ->selectRaw('transactions.tenant_id, COUNT(DISTINCT transactions.id) as with_tax')
            ->whereBetween('transactions.created_at', [$from, $to])
            ->when($tenantId !== null, fn ($q) => $q->where('transactions.tenant_id', $tenantId))
            ->groupBy('transactions.tenant_id')
            ->pluck('with_tax', 'tenant_id');

        $quarantinedByTenant = DB::table('transactions')
            ->join('tax_backfill_records', 'tax_backfill_records.transaction_pk', '=', 'transactions.id')
            ->where('tax_backfill_records.outcome', 'quarantined')
            ->selectRaw('transactions.tenant_id, COUNT(DISTINCT transactions.id) as quarantined')
            ->whereBetween('transactions.created_at', [$from, $to])
            ->when($tenantId !== null, fn ($q) => $q->where('transactions.tenant_id', $tenantId))
            ->groupBy('transactions.tenant_id')
            ->pluck('quarantined', 'tenant_id');

        $unexpectedZero = [];
        $expectedZero = [];
        $tenantsChecked = 0;

        foreach ($totalByTenant as $tenant => $total) {
            $tenantsChecked++;
            $withTax = (int) ($withTaxByTenant[$tenant] ?? 0);
            $quarantined = (int) ($quarantinedByTenant[$tenant] ?? 0);
            $nonQuarantined = (int) $total - $quarantined;

            if ($withTax > 0) {
                continue;
            }

            $entry = [
                'tenant_id' => (int) $tenant,
                'total' => (int) $total,
                'quarantined' => $quarantined,
            ];

            if ($nonQuarantined === 0) {
                $expectedZero[] = $entry;
            } else {
                $unexpectedZero[] = $entry;
            }
        }

        return [
            'status' => empty($unexpectedZero) ? 'pass' : 'fail',
            'reason' => empty($unexpectedZero)
                ? "All {$tenantsChecked} affected tenant(s) have backfilled data, or are expected_zero (100% quarantined)."
                : count($unexpectedZero).' tenant(s) have zero backfilled rows despite having non-quarantined transactions.',
            'tenants_checked' => $tenantsChecked,
            'unexpected_zero' => $unexpectedZero,
            'expected_zero' => $expectedZero,
        ];
    }

    /**
     * T060: compares each tax_type's share of rows in the backfilled window
     * against its share in the post-fix reference period. WARN-only — a
     * skew is directional evidence worth reviewing, never proof of a bug on
     * its own, so this check never fails the overall verdict by itself.
     */
    protected function checkTaxTypeDistribution(
        Carbon $windowFrom,
        Carbon $windowTo,
        Carbon $referenceFrom,
        ?Carbon $referenceTo,
        ?int $tenantId,
        float $band,
        int $floor
    ): array {
        $windowCounts = $this->taxTypeCounts($windowFrom, $windowTo, $tenantId);
        $referenceCounts = $this->taxTypeCounts($referenceFrom, $referenceTo, $tenantId);

        $windowTotal = array_sum($windowCounts);
        $referenceTotal = array_sum($referenceCounts);

        if ($windowTotal === 0 || $referenceTotal === 0) {
            return [
                'status' => 'pass',
                'reason' => 'Insufficient data in the window or reference period to compare tax-type distribution.',
                'window_total' => $windowTotal,
                'reference_total' => $referenceTotal,
                'flagged_types' => [],
            ];
        }

        $flagged = [];
        $types = array_unique(array_merge(array_keys($windowCounts), array_keys($referenceCounts)));

        foreach ($types as $type) {
            $windowCount = $windowCounts[$type] ?? 0;
            $referenceCount = $referenceCounts[$type] ?? 0;

            if ($windowCount < $floor || $referenceCount < $floor) {
                continue;
            }

            $windowShare = $windowCount / $windowTotal * 100;
            $referenceShare = $referenceCount / $referenceTotal * 100;
            $deltaPp = abs($windowShare - $referenceShare);

            if ($deltaPp > $band) {
                $flagged[] = [
                    'tax_type' => $type,
                    'window_count' => $windowCount,
                    'window_share_pct' => round($windowShare, 2),
                    'reference_count' => $referenceCount,
                    'reference_share_pct' => round($referenceShare, 2),
                    'delta_pp' => round($deltaPp, 2),
                ];
            }
        }

        return [
            'status' => empty($flagged) ? 'pass' : 'warn',
            'reason' => empty($flagged)
                ? 'No tax type exceeds the configured skew band relative to the reference period.'
                : count($flagged)." tax type(s) exceed the {$band}pp skew band (floor: {$floor} rows/period) — review for a possible reconstruction issue.",
            'window_total' => $windowTotal,
            'reference_total' => $referenceTotal,
            'flagged_types' => $flagged,
        ];
    }

    /**
     * $to, when given, is an inclusive upper bound (callers already resolve
     * it that way — see resolveWindow()'s subSecond() adjustment), matching
     * every other check's whereBetween usage in this file.
     *
     * @return array<string, int> tax_type (normalized) => row count
     */
    protected function taxTypeCounts(Carbon $from, ?Carbon $to, ?int $tenantId): array
    {
        return DB::table('transactions')
            ->join('transaction_taxes', 'transaction_taxes.transaction_pk', '=', 'transactions.id')
            ->selectRaw('UPPER(TRIM(transaction_taxes.tax_type)) as tax_type, COUNT(*) as cnt')
            ->where('transactions.created_at', '>=', $from)
            ->when($to !== null, fn ($q) => $q->where('transactions.created_at', '<=', $to))
            ->when($tenantId !== null, fn ($q) => $q->where('transactions.tenant_id', $tenantId))
            ->groupBy('tax_type')
            ->pluck('cnt', 'tax_type')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * T061: per tenant/month, reconstructed VAT-aliased tax rows MUST
     * reconcile against transactions.vat_amount within FR-009a's threshold
     * (PHP 500 or 1%, whichever is looser).
     */
    protected function checkVatReconciliation(Carbon $from, Carbon $to, ?int $tenantId): array
    {
        $taxSums = DB::table('transactions')
            ->join('transaction_taxes', 'transaction_taxes.transaction_pk', '=', 'transactions.id')
            ->whereIn(DB::raw('UPPER(TRIM(transaction_taxes.tax_type))'), self::VAT_TAX_TYPE_ALIASES)
            ->whereBetween('transactions.created_at', [$from, $to])
            ->when($tenantId !== null, fn ($q) => $q->where('transactions.tenant_id', $tenantId))
            ->selectRaw("transactions.tenant_id, DATE_FORMAT(transactions.created_at, '%Y-%m') as ym, SUM(transaction_taxes.amount) as tax_sum")
            ->groupBy('transactions.tenant_id', 'ym')
            ->get()
            ->keyBy(fn ($row) => "{$row->tenant_id}|{$row->ym}");

        $vatAmountSums = DB::table('transactions')
            ->whereBetween('created_at', [$from, $to])
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->selectRaw("tenant_id, DATE_FORMAT(created_at, '%Y-%m') as ym, SUM(vat_amount) as vat_sum")
            ->groupBy('tenant_id', 'ym')
            ->get()
            ->keyBy(fn ($row) => "{$row->tenant_id}|{$row->ym}");

        $failingPairs = [];
        $pairKeys = array_unique(array_merge($taxSums->keys()->all(), $vatAmountSums->keys()->all()));
        $pairsChecked = count($pairKeys);

        foreach ($pairKeys as $key) {
            [$tenant, $ym] = explode('|', $key, 2);
            $taxSum = (float) ($taxSums[$key]->tax_sum ?? 0);
            $vatSum = (float) ($vatAmountSums[$key]->vat_sum ?? 0);
            $diff = abs($taxSum - $vatSum);
            $allowedThreshold = max(500.0, abs($vatSum) * 0.01);

            if ($diff > $allowedThreshold) {
                $failingPairs[] = [
                    'tenant_id' => (int) $tenant,
                    'month' => $ym,
                    'tax_sum' => round($taxSum, 2),
                    'vat_amount_sum' => round($vatSum, 2),
                    'diff' => round($diff, 2),
                    'allowed_threshold' => round($allowedThreshold, 2),
                ];
            }
        }

        return [
            'status' => empty($failingPairs) ? 'pass' : 'fail',
            'reason' => empty($failingPairs)
                ? "All {$pairsChecked} tenant/month pair(s) reconcile within FR-009a's threshold (PHP 500 or 1%, whichever is looser)."
                : count($failingPairs).' of '.$pairsChecked.' tenant/month pair(s) exceed the reconciliation threshold.',
            'pairs_checked' => $pairsChecked,
            'failing_pairs' => $failingPairs,
        ];
    }

    protected function overallStatus(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        if (in_array('fail', $statuses, true)) {
            return 'fail';
        }

        if (in_array('warn', $statuses, true)) {
            return 'warn';
        }

        return 'pass';
    }

    protected function render(array $result): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info(sprintf(
            'Validation verdict: %s — window %s to %s%s',
            strtoupper($result['overall_status']),
            $result['window']['start'],
            $result['window']['end'],
            $result['tenant_id'] !== null ? " — tenant #{$result['tenant_id']}" : ''
        ));

        $this->table(
            ['Check', 'Status', 'Reason'],
            array_map(
                fn (string $name, array $check) => [$name, strtoupper($check['status']), $check['reason']],
                array_keys($result['checks']),
                array_values($result['checks'])
            )
        );
    }
}
