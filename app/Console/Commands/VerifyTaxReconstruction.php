<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\Backfill\TaxReconstructionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

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
            $result = $this->buildResult($from, $sampleSize, 0, 0, []);
            $this->render($result);

            // An empty candidate pool proves nothing. Treating this as a
            // failure (not a vacuous "0 divergences, success") is deliberate
            // per the feature's brief — nobody should mistake "found nothing
            // to check" for "verified clean".
            return self::FAILURE;
        }

        // Sampling method: a random contiguous window, ordered by `id`, via
        // offset()->limit(). Deliberately NOT inRandomOrder()->limit() —
        // that compiles to `ORDER BY RAND() LIMIT N`, which forces MySQL to
        // filesort the *entire* candidate pool on every invocation. This
        // command is meant to run routinely against a growing live
        // population (research.md: ~55K+/day post-fix transactions, growing
        // indefinitely), and research.md separately documents a real prior
        // production incident caused by exactly this class of heavy,
        // full-table-scanning query against `transactions` — this command
        // must not reintroduce it.
        //
        // Picking a random offset in [0, candidatePoolSize - sampleSize] and
        // reading `sampleSize` rows from there, ordered by `id`, avoids the
        // filesort while still avoiding the oldest-N bias a bare
        // orderBy('id')->limit() would have. This is an approximation, not
        // true uniform random — it samples a contiguous block of ids rather
        // than an independently-random subset, so rows adjacent to each
        // other in id order are always sampled together. That's an
        // acceptable tradeoff for this slice: it's a simple, non-stratified
        // sample by design, and T101 (tasks.md) is the tracked, separate
        // follow-up that replaces this selection query entirely with proper
        // stratified-by-tenant/day sampling — nothing here hardcodes an
        // assumption T101 would need to unwind.
        $maxOffset = max(0, $candidatePoolSize - $sampleSize);
        $randomOffset = $maxOffset > 0 ? random_int(0, $maxOffset) : 0;

        $transactions = (clone $candidateQuery)
            ->with('taxes')
            ->orderBy('id')
            ->offset($randomOffset)
            ->limit($sampleSize)
            ->get();

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

        $result = $this->buildResult($from, $sampleSize, $candidatePoolSize, $transactions->count(), $divergences);

        $this->render($result);

        return $divergences === [] ? self::SUCCESS : self::FAILURE;
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
     * @return array{
     *     from: string,
     *     sample_requested: int,
     *     candidate_pool_size: int,
     *     checked_count: int,
     *     divergence_count: int,
     *     divergences: array,
     * }
     */
    protected function buildResult(Carbon $from, int $sampleSize, int $candidatePoolSize, int $checkedCount, array $divergences): array
    {
        return [
            'from' => $from->toDateTimeString(),
            'sample_requested' => $sampleSize,
            'candidate_pool_size' => $candidatePoolSize,
            'checked_count' => $checkedCount,
            'divergence_count' => count($divergences),
            'divergences' => $divergences,
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
