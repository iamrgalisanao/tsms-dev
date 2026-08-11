<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\Backfill\TaxReconstructionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 002-backfill-transaction-taxes, Slice 5 (T023-T025).
 *
 * This is the feature's primary safety gate (cli-contract.md Command 2,
 * research.md R4): "This command must report zero divergences before
 * Command 1 is ever run with --apply." It replays TaxReconstructionService
 * against transactions where the ground truth is already known — post-fix
 * transactions that already carry real, correct `transaction_taxes` rows —
 * and checks that reconstruction reproduces those rows exactly.
 *
 * This is NOT a dry-run backfill preview (BackfillTransactionTaxes/Slice 4's
 * job) and it is NOT TaxReconstructionService::crossCheck() (which compares
 * against the three transactions.* summary columns). This command compares
 * reconstructed candidate rows against the actual persisted transaction_taxes
 * rows for the same transaction, as a multiset of (tax_type, amount) pairs —
 * order-independent, and tolerant of legitimate duplicate tax_type rows —
 * because the real ingestion path (TransactionIngestService::insertTaxes())
 * writes every valid row without deduplication.
 *
 * Read-only. No --apply flag exists here and none is planned — this command
 * never writes to any table.
 */
class VerifyTaxReconstruction extends Command
{
    protected $signature = 'transactions:verify-tax-reconstruction
        {--from= : Window start (Y-m-d or Y-m-d H:i:s). Default: first unambiguously post-fix instant.}
        {--sample=500 : Number of candidate transactions to check.}
        {--json : Emit machine-readable JSON instead of a summary table.}';

    protected $description = 'Verify TaxReconstructionService against known-good post-fix transaction_taxes rows (read-only safety gate)';

    /**
     * research.md R4 dates the fix at "2026-08-10 ~10:00" — the `~` signals
     * genuine imprecision about the exact cutover instant. Since this
     * command's entire purpose is validating against *known-good* data, the
     * default must land safely on the post-fix side of that boundary rather
     * than risk straddling it. Defaulting to the imprecise ~10:00 mark itself
     * could pull in a handful of pre-fix stragglers (research.md V1a notes
     * 2026-08-10 is a partial day: 2,694 with taxes / 2,032 without, straddling
     * the fix). Defaulting instead to the first unambiguously post-fix
     * calendar day — 2026-08-11 00:00:00 — guarantees every default-run
     * candidate is genuinely post-fix, at the cost of excluding same-day
     * (2026-08-10) stragglers from the default sample. An operator who wants
     * a larger sample and is willing to accept that boundary imprecision can
     * override with --from=2026-08-10 or a more precise timestamp.
     */
    protected const DEFAULT_FROM = '2026-08-11 00:00:00';

    /**
     * A single (tenant, day) stratum is assumed small (a handful to a few
     * hundred rows) based on this feature's known data shape (research.md:
     * ~87 tenants, ~55K+/day post-fix transactions), not on a direct
     * measurement against representative staging/production data — no such
     * access was available at implementation time. This threshold is a
     * conservative safety net, not a proven cutoff: if a single stratum
     * ever exceeds it (e.g. one unusually high-volume tenant on one day),
     * drawStratumRows() falls back to the same random-offset-window
     * technique the original full-pool sampling used (see the class-level
     * comment history / Slice 5) rather than assume inRandomOrder()'s
     * filesort stays cheap at any stratum size.
     */
    protected const STRATUM_INRANDOMORDER_THRESHOLD = 2000;

    public function handle(TaxReconstructionService $service): int
    {
        $from = $this->resolveFrom();

        if ($from === null) {
            $this->error("Invalid --from value '{$this->option('from')}': expected format Y-m-d or Y-m-d H:i:s.");

            return self::FAILURE;
        }

        $sampleSize = $this->option('sample');

        if (! ctype_digit((string) $sampleSize) || (int) $sampleSize <= 0) {
            $this->error("Invalid --sample value '{$sampleSize}': must be a positive integer.");

            return self::FAILURE;
        }

        $sampleSize = (int) $sampleSize;

        // Candidate population: transactions created on/after $from that
        // already have at least one linked transaction_taxes row — i.e. real,
        // already-correct data usable as an oracle. Transactions without
        // linked rows have nothing to compare against and are excluded here,
        // not silently skipped mid-check.
        $candidateQuery = Transaction::query()
            ->where('created_at', '>=', $from)
            ->whereHas('taxes');

        $candidatePoolSize = (clone $candidateQuery)->count();

        if ($candidatePoolSize === 0) {
            $result = $this->buildResult($from, $sampleSize, 0, 0, [], $this->emptyCoverage());
            $this->render($result);

            // An empty candidate pool proves nothing. Treating this as a
            // failure (not a vacuous "0 divergences, success") is deliberate
            // per the feature's brief — nobody should mistake "found nothing
            // to check" for "verified clean".
            return self::FAILURE;
        }

        // Sampling method (T101, Slice 11): two-level stratified sampling —
        // first across days, then across tenants within each day — using
        // round-robin water-filling (allocate()) at both levels. This
        // replaces the original random-offset contiguous-id window (see git
        // history/Slice 5 for that design and its filesort-avoidance
        // reasoning), which gave no breadth guarantee: a run could, by
        // chance, sample almost entirely from one or two large tenants on
        // one or two days. See sampleStratified() for the query shape and
        // why inRandomOrder() is safe at the per-stratum level even though
        // it was deliberately avoided at full-pool scale.
        [$transactions, $coverage] = $this->sampleStratified($candidateQuery, $sampleSize);

        $divergences = [];

        foreach ($transactions as $transaction) {
            $reconstructed = $service->reconstructTaxRows($transaction);
            $actual = $transaction->taxes
                ->map(fn ($tax) => ['tax_type' => $tax->tax_type, 'amount' => $tax->amount])
                ->all();

            $descriptions = $this->diff($reconstructed, $actual);

            if ($descriptions !== []) {
                $divergences[] = [
                    'transaction_id' => $transaction->id,
                    'reconstructed' => $reconstructed,
                    'actual' => $actual,
                    'descriptions' => $descriptions,
                ];
            }
        }

        $result = $this->buildResult($from, $sampleSize, $candidatePoolSize, $transactions->count(), $divergences, $coverage);

        $this->render($result);

        return $divergences === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Round-robin water-filling allocator (T101). Pure function: no DB
     * access, no randomness. Every stratum gets one unit before any stratum
     * gets a second, so breadth is maximized first — additional depth is
     * only added once every stratum already has equal representation. This
     * is deliberately NOT population-proportional: a proportional
     * allocation would still let the sample concentrate on the largest few
     * strata when $total is small relative to the number of buckets, which
     * is exactly the breadth failure this method exists to close.
     *
     * $capacities must already be in the stable/deterministic order the
     * caller wants ties broken in (e.g. ksort() by stratum key) — this
     * method iterates in the order given and does not sort internally, so
     * the same ($total, $capacities) always produces the same allocation.
     *
     * @param  array<int|string, int>  $capacities  stratum key => capacity, in caller-chosen stable order
     * @return array<int|string, int> stratum key => allocated count (same keys/order as $capacities)
     */
    protected function allocate(int $total, array $capacities): array
    {
        $allocated = array_fill_keys(array_keys($capacities), 0);
        $remaining = $total;

        while ($remaining > 0) {
            $madeProgress = false;

            foreach ($capacities as $key => $capacity) {
                if ($remaining === 0) {
                    break;
                }

                if ($allocated[$key] < $capacity) {
                    $allocated[$key]++;
                    $remaining--;
                    $madeProgress = true;
                }
            }

            // Every stratum is already at full capacity (sample size >=
            // pool size, or $capacities is empty) — stop instead of
            // spinning forever.
            if (! $madeProgress) {
                break;
            }
        }

        return $allocated;
    }

    /**
     * Two-level stratified sampling (T101): allocate() across days, then
     * allocate() again across tenants within each day, then draw each
     * (day, tenant) stratum's quota directly.
     *
     * Query shape: a single query groups the candidate pool by
     * (DATE(created_at), tenant_id) up front, which yields everything
     * needed for both allocation levels AND the full-pool coverage
     * pool-counts (including days that end up with a zero sample quota) —
     * this deliberately trades the brief's illustrative "one day-count
     * query, then one tenant-count query per day that gets a non-zero
     * quota" shape for a single grouped query, because per_tenant/
     * total_strata coverage needs every day's tenant breakdown regardless
     * of whether that day was allocated any sample quota, and computing
     * that from a per-quota-day-only query would under-report coverage for
     * a candidate pool where --sample is smaller than the number of days.
     *
     * @return array{0: Collection<int, Transaction>, 1: array}
     */
    protected function sampleStratified(Builder $candidateQuery, int $sampleSize): array
    {
        $strataRows = (clone $candidateQuery)
            ->selectRaw('DATE(created_at) as day, tenant_id, COUNT(*) as cnt')
            ->groupBy('day', 'tenant_id')
            ->orderBy('day')
            ->orderBy('tenant_id')
            ->get();

        $dayCounts = [];
        $tenantCountsByDay = [];
        $tenantPoolCounts = [];

        foreach ($strataRows as $row) {
            $day = (string) $row->day;
            $tenantId = (int) $row->tenant_id;
            $count = (int) $row->cnt;

            $dayCounts[$day] = ($dayCounts[$day] ?? 0) + $count;
            $tenantCountsByDay[$day][$tenantId] = $count;
            $tenantPoolCounts[$tenantId] = ($tenantPoolCounts[$tenantId] ?? 0) + $count;
        }

        // ksort gives allocate() a stable, deterministic iteration order
        // (chronological for days — Y-m-d strings sort correctly as
        // strings; numeric for tenant ids) independent of whatever order
        // the DB happened to return rows in.
        ksort($dayCounts);
        ksort($tenantPoolCounts);

        $totalStrata = 0;
        foreach ($tenantCountsByDay as $tenantsForDay) {
            $totalStrata += count($tenantsForDay);
        }

        $dayQuotas = $this->allocate($sampleSize, $dayCounts);

        $transactions = collect();
        $tenantSampledCounts = [];
        $perDay = [];
        $sampledStrata = 0;

        foreach ($dayCounts as $day => $poolCount) {
            $dayQuota = $dayQuotas[$day] ?? 0;

            $perDay[] = [
                'day' => $day,
                'pool_count' => $poolCount,
                'sampled_count' => $dayQuota,
            ];

            if ($dayQuota === 0) {
                continue;
            }

            $tenantCounts = $tenantCountsByDay[$day];
            ksort($tenantCounts);

            $tenantQuotas = $this->allocate($dayQuota, $tenantCounts);

            foreach ($tenantQuotas as $tenantId => $quota) {
                if ($quota === 0) {
                    continue;
                }

                $sampledStrata++;
                $tenantSampledCounts[$tenantId] = ($tenantSampledCounts[$tenantId] ?? 0) + $quota;

                $stratumTransactions = $this->drawStratumRows($candidateQuery, $tenantId, $day, $quota, $tenantCounts[$tenantId]);

                $transactions = $transactions->merge($stratumTransactions);
            }
        }

        $perTenant = [];

        foreach ($tenantPoolCounts as $tenantId => $poolCount) {
            $perTenant[] = [
                'tenant_id' => $tenantId,
                'pool_count' => $poolCount,
                'sampled_count' => $tenantSampledCounts[$tenantId] ?? 0,
            ];
        }

        $coverage = [
            'total_strata' => $totalStrata,
            'sampled_strata' => $sampledStrata,
            'per_day' => $perDay,
            'per_tenant' => $perTenant,
        ];

        return [$transactions, $coverage];
    }

    /**
     * Draws $quota rows for one (tenant, day) stratum, extending
     * $candidateQuery (not a fresh query) so the `--from` lower bound and
     * whereHas('taxes') filter always stay in sync with the candidate
     * population's own definition.
     */
    protected function drawStratumRows(Builder $candidateQuery, int $tenantId, string $day, int $quota, int $stratumSize): Collection
    {
        $stratumQuery = (clone $candidateQuery)
            ->where('tenant_id', $tenantId)
            ->whereDate('created_at', $day)
            ->with('taxes');

        if ($stratumSize <= self::STRATUM_INRANDOMORDER_THRESHOLD) {
            return $stratumQuery->inRandomOrder()->limit($quota)->get();
        }

        // Same random-offset-window approach as the pre-T101 full-pool
        // sampling: avoids filesorting this (unusually large) stratum while
        // still avoiding the oldest-N bias a bare orderBy('id')->limit()
        // would have.
        $maxOffset = max(0, $stratumSize - $quota);
        $randomOffset = $maxOffset > 0 ? random_int(0, $maxOffset) : 0;

        return $stratumQuery
            ->orderBy('id')
            ->offset($randomOffset)
            ->limit($quota)
            ->get();
    }

    /**
     * @return array{total_strata: int, sampled_strata: int, per_day: array, per_tenant: array}
     */
    protected function emptyCoverage(): array
    {
        return [
            'total_strata' => 0,
            'sampled_strata' => 0,
            'per_day' => [],
            'per_tenant' => [],
        ];
    }

    protected function resolveFrom(): ?Carbon
    {
        $raw = $this->option('from');

        if ($raw === null || $raw === '') {
            return Carbon::parse(self::DEFAULT_FROM);
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Compares reconstructed vs. actual tax rows as multisets of
     * (tax_type, amount) pairs and returns a human-readable description for
     * every difference found. An empty return means a full match — same
     * tax_type appearing more than once with the same amount on both sides
     * is not a divergence (T3: multiset, not naive per-index, comparison).
     *
     * Grouped by tax_type first, then the amounts within each type are
     * compared as sorted lists: this produces "amount mismatch" messages
     * for like-for-like pairs (e.g. the Nth-smallest VAT row on each side)
     * rather than just reporting a generic count mismatch, while still
     * falling back to explicit missing/extra messages for any genuine count
     * imbalance within a type.
     *
     * @param  array<int, array{tax_type: mixed, amount: mixed}>  $reconstructed
     * @param  array<int, array{tax_type: mixed, amount: mixed}>  $actual
     * @return list<string>
     */
    protected function diff(array $reconstructed, array $actual): array
    {
        $byType = [];

        foreach ($reconstructed as $row) {
            $byType[(string) $row['tax_type']]['reconstructed'][] = $this->normalizeAmount($row['amount']);
        }

        foreach ($actual as $row) {
            $byType[(string) $row['tax_type']]['actual'][] = $this->normalizeAmount($row['amount']);
        }

        $descriptions = [];

        foreach ($byType as $type => $sides) {
            $reconstructedAmounts = $sides['reconstructed'] ?? [];
            $actualAmounts = $sides['actual'] ?? [];

            sort($reconstructedAmounts, SORT_NUMERIC);
            sort($actualAmounts, SORT_NUMERIC);

            $pairCount = min(count($reconstructedAmounts), count($actualAmounts));

            for ($i = 0; $i < $pairCount; $i++) {
                if ($reconstructedAmounts[$i] !== $actualAmounts[$i]) {
                    $descriptions[] = "amount mismatch on {$type}: reconstructed {$reconstructedAmounts[$i]} vs actual {$actualAmounts[$i]}";
                }
            }

            for ($i = $pairCount; $i < count($reconstructedAmounts); $i++) {
                $descriptions[] = "extra {$type} row in reconstruction (amount {$reconstructedAmounts[$i]}) not present in actual";
            }

            for ($i = $pairCount; $i < count($actualAmounts); $i++) {
                $descriptions[] = "missing {$type} row: actual has amount {$actualAmounts[$i]} but reconstruction produced none";
            }
        }

        return $descriptions;
    }

    /**
     * Same money-comparison convention as
     * TaxReconstructionService::amountsMatch() — 2-decimal rounding to
     * absorb float precision noise — formatted as a fixed 2-decimal string
     * so it can be used directly in both sorting and human-readable output.
     */
    protected function normalizeAmount(mixed $amount): string
    {
        return number_format(round((float) $amount, 2), 2, '.', '');
    }

    /**
     * One result array drives both the human table and --json output.
     *
     * @param  list<array{transaction_id: int, reconstructed: array, actual: array, descriptions: list<string>}>  $divergences
     * @param  array{total_strata: int, sampled_strata: int, per_day: array, per_tenant: array}  $coverage
     * @return array{
     *     from: string,
     *     sample_requested: int,
     *     candidate_pool_size: int,
     *     checked_count: int,
     *     divergence_count: int,
     *     divergences: array,
     *     coverage: array,
     * }
     */
    protected function buildResult(Carbon $from, int $sampleSize, int $candidatePoolSize, int $checkedCount, array $divergences, array $coverage): array
    {
        return [
            'from' => $from->toDateTimeString(),
            'sample_requested' => $sampleSize,
            'candidate_pool_size' => $candidatePoolSize,
            'checked_count' => $checkedCount,
            'divergence_count' => count($divergences),
            'divergences' => $divergences,
            'coverage' => $coverage,
        ];
    }

    protected function render(array $result): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        if ($result['candidate_pool_size'] === 0) {
            $this->error(sprintf(
                'No candidate transactions found for verification (created_at >= %s AND at least one linked transaction_taxes row). '.
                'This is a failure of the safety gate, not a vacuous pass — 0 checked does not mean 0 divergences.',
                $result['from']
            ));

            return;
        }

        $this->info(sprintf(
            'Tax reconstruction verification — window from %s (candidate pool: %d, checked: %d)',
            $result['from'],
            $result['candidate_pool_size'],
            $result['checked_count']
        ));

        $this->table(
            ['Sample requested', 'Candidate pool', 'Checked', 'Divergences'],
            [[
                $result['sample_requested'],
                $result['candidate_pool_size'],
                $result['checked_count'],
                $result['divergence_count'],
            ]]
        );

        $this->renderCoverage($result['coverage']);

        if ($result['divergence_count'] === 0) {
            $this->info('Zero divergences — reconstruction matches actual tax rows for every checked transaction.');

            return;
        }

        $this->error("{$result['divergence_count']} divergence(s) found. Make failure loud — every mismatch is listed below.");

        foreach ($result['divergences'] as $divergence) {
            $this->line('');
            $this->line("Transaction #{$divergence['transaction_id']}:");
            $this->line('  Reconstructed: '.$this->formatRows($divergence['reconstructed']));
            $this->line('  Actual:        '.$this->formatRows($divergence['actual']));

            foreach ($divergence['descriptions'] as $description) {
                $this->line("  - {$description}");
            }
        }
    }

    /**
     * T101 (Slice 11): reporting-only breadth summary of the stratified
     * sample — does not affect the pass/fail verdict. Shows every distinct
     * day/tenant in the candidate pool (not just the ones that got sampled)
     * so an operator can see which strata, if any, got zero coverage when
     * --sample is smaller than total_strata.
     *
     * @param  array{total_strata: int, sampled_strata: int, per_day: array, per_tenant: array}  $coverage
     */
    protected function renderCoverage(array $coverage): void
    {
        $this->line('');
        $this->info(sprintf(
            'Coverage — %d of %d distinct (day, tenant) strata sampled.',
            $coverage['sampled_strata'],
            $coverage['total_strata']
        ));

        if ($coverage['per_day'] !== []) {
            $this->line('Per day:');
            $this->table(
                ['Day', 'Pool count', 'Sampled count'],
                collect($coverage['per_day'])->map(fn ($row) => [
                    $row['day'],
                    $row['pool_count'],
                    $row['sampled_count'],
                ])->all()
            );
        }

        if ($coverage['per_tenant'] !== []) {
            $this->line('Per tenant:');
            $this->table(
                ['Tenant', 'Pool count', 'Sampled count'],
                collect($coverage['per_tenant'])->map(fn ($row) => [
                    $row['tenant_id'],
                    $row['pool_count'],
                    $row['sampled_count'],
                ])->all()
            );
        }
    }

    /**
     * @param  array<int, array{tax_type: mixed, amount: mixed}>  $rows
     */
    protected function formatRows(array $rows): string
    {
        if ($rows === []) {
            return '(none)';
        }

        return collect($rows)
            ->map(fn ($row) => "{$row['tax_type']}: {$this->normalizeAmount($row['amount'])}")
            ->implode(', ');
    }
}
