<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PreRunIntegrityCapture;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * 002-backfill-transaction-taxes, Slice 16 (T075/T099) — see
 * specs/002-backfill-transaction-taxes/slice-16-integrity-evidence-capture-brief.md.
 *
 * Captures and durably persists the pre-run integrity evidence T076's
 * post-run comparison will later need:
 *
 *  1. The corrected T062 duplicate-check baseline — `SELECT transaction_pk,
 *     tax_type, COUNT(*) FROM transaction_taxes WHERE transaction_pk IS NOT
 *     NULL GROUP BY transaction_pk, tax_type HAVING COUNT(*) > 1`. The `WHERE
 *     transaction_pk IS NOT NULL` clause is mandatory (see the brief's
 *     "Grounding" section) — MySQL's GROUP BY treats every NULL
 *     transaction_pk as one single group, so omitting it would collapse the
 *     millions of orphan rows into a handful of meaningless "duplicate"
 *     groups. Whole-table, never scoped to --from/--to, because T076's
 *     post-run assertion is itself a whole-table check and the two must be
 *     directly comparable.
 *  2. The existing `txn:pk-integrity` command's verbatim console output
 *     (T099) — invoked via Artisan::call(), never reimplemented.
 *
 * This command is read-only against transaction_taxes/transactions — its
 * only write target is its own pre_run_integrity_captures table, and only
 * when --apply is given. It does not implement T076's post-run comparison
 * or any materiality logic, and it is not coupled to Slice 15's
 * pre_backfill_snapshot_runs/_records tables in any way.
 *
 * Dry-run (no --apply) still RUNS both checks and displays them — unlike
 * Slice 15's dry-run, which skips its expensive report calls entirely, both
 * of these checks are cheap enough relative to the aggregate-snapshot path
 * that previewing them before persisting costs nothing extra (Design
 * decision 4 of the brief).
 */
class CaptureIntegrityEvidence extends Command
{
    /**
     * Defensive bound on the persisted `sample` array — not because a large
     * duplicate-group result is expected, but so a pathological case can
     * never produce an unbounded JSON blob. total_duplicate_groups/
     * total_duplicate_rows always reflect the true, uncapped totals.
     */
    protected const SAMPLE_CAP = 200;

    protected $signature = 'transactions:capture-integrity-evidence
        {--from= : Window start (Y-m-d). Required — metadata only, not a query filter.}
        {--to= : Window end, exclusive (Y-m-d). Required.}
        {--phase=pre_run : pre_run | post_run. This slice only exercises pre_run.}
        {--apply : Persist. Without this flag: run both checks and display them, write nothing.}
        {--json}';

    protected $description = 'Capture and persist pre-run integrity evidence (T062 corrected duplicate-check baseline + verbatim txn:pk-integrity report) ahead of the tax backfill';

    public function handle(): int
    {
        $validationError = $this->validateInput();

        if ($validationError !== null) {
            $this->error($validationError);

            return self::FAILURE;
        }

        [$windowStart, $windowEnd] = $this->resolveWindow();
        $phase = (string) $this->option('phase');
        $apply = (bool) $this->option('apply');

        // Both checks are read-only and cheap enough to always run, dry-run
        // included (Design decision 4) — there is no flag that skips them.
        $duplicateCheckSummary = $this->runDuplicateCheck();
        $nullCount = $this->runNullCountCheck();

        // Code-review follow-up: Illuminate\Console\Application keeps a
        // single shared $lastOutput property, overwritten by every nested
        // Artisan::call() — harmless here since this command's own output
        // goes through Symfony's normal $this->line()/$this->table() output
        // stream, never through Artisan::output(). But if a future command
        // (e.g. a T100-102 orchestration wrapper) ever chains THIS command
        // via a nested Artisan::call() and reads it back via
        // Artisan::output(), that call would need the same explicit
        // BufferedOutput-as-third-argument workaround this slice's own test
        // suite already uses (see CaptureBackfillIntegrityEvidenceTest::runCommand()).
        Artisan::call('txn:pk-integrity');
        $integrityReport = Artisan::output();

        $captureId = null;

        if ($apply) {
            $capture = PreRunIntegrityCapture::create([
                'window_start' => $windowStart,
                'window_end' => $windowEnd,
                'phase' => $phase,
                'duplicate_check_summary' => $duplicateCheckSummary,
                'transaction_taxes_null_count' => $nullCount,
                'integrity_report' => $integrityReport,
                'captured_at' => now(),
            ]);

            $captureId = $capture->id;
        }

        $result = $this->buildResult(
            $apply ? 'apply' : 'dry-run',
            $windowStart,
            $windowEnd,
            $phase,
            $duplicateCheckSummary,
            $nullCount,
            $integrityReport,
            $captureId
        );

        $this->render($result);

        return self::SUCCESS;
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

        $phase = (string) $this->option('phase');

        if (! in_array($phase, [PreRunIntegrityCapture::PHASE_PRE_RUN, PreRunIntegrityCapture::PHASE_POST_RUN], true)) {
            return "Invalid --phase value '{$phase}': must be one of: pre_run, post_run.";
        }

        return null;
    }

    protected function isValidDate(string $value): bool
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * `--to` is exclusive per the CLI contract — window_start/window_end are
     * stored and reported literally as given (start-of-day of --from,
     * start-of-day of --to). Metadata only: see runDuplicateCheck()'s
     * docblock for why the duplicate query itself never uses this window.
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
     * The corrected T062 duplicate-check query (Design decision 2 of the
     * brief) — whole-table, `WHERE transaction_pk IS NOT NULL` mandatory.
     * Never remove that clause: MySQL's GROUP BY treats every NULL
     * transaction_pk as a single group, so omitting it would collapse the
     * orphan population into meaningless "duplicate" groups.
     *
     * total_duplicate_groups/total_duplicate_rows always reflect the true,
     * uncapped aggregate result; only the persisted `sample` is capped
     * (SAMPLE_CAP) as a defensive bound.
     *
     * @return array{total_duplicate_groups: int, total_duplicate_rows: int, sample: list<array{transaction_pk: int, tax_type: string, count: int}>}
     */
    protected function runDuplicateCheck(): array
    {
        $groups = DB::table('transaction_taxes')
            ->select(['transaction_pk', 'tax_type'])
            ->selectRaw('COUNT(*) as cnt')
            ->whereNotNull('transaction_pk')
            ->groupBy('transaction_pk', 'tax_type')
            ->having('cnt', '>', 1)
            ->get();

        $totalDuplicateGroups = $groups->count();
        $totalDuplicateRows = (int) $groups->sum('cnt');

        $sample = $groups
            ->take(self::SAMPLE_CAP)
            ->map(fn ($group) => [
                'transaction_pk' => (int) $group->transaction_pk,
                'tax_type' => $group->tax_type,
                'count' => (int) $group->cnt,
            ])
            ->values()
            ->all();

        return [
            'total_duplicate_groups' => $totalDuplicateGroups,
            'total_duplicate_rows' => $totalDuplicateRows,
            'sample' => $sample,
        ];
    }

    /**
     * `transaction_pk IS NULL` count, whole-table, same scope discipline as
     * runDuplicateCheck() — structured so
     * transactions:tax-backfill-readiness-verdict (T076, Slice 20) never
     * has to parse the verbatim integrity_report text to find this number.
     */
    protected function runNullCountCheck(): int
    {
        return DB::table('transaction_taxes')->whereNull('transaction_pk')->count();
    }

    /**
     * One result array drives both the human table and --json output — no
     * separate/duplicated formatting logic that could drift between them
     * (this feature's established one-result-object convention).
     *
     * @param  array{total_duplicate_groups: int, total_duplicate_rows: int, sample: array}  $duplicateCheckSummary
     */
    protected function buildResult(
        string $mode,
        Carbon $windowStart,
        Carbon $windowEnd,
        string $phase,
        array $duplicateCheckSummary,
        int $nullCount,
        string $integrityReport,
        ?int $captureId
    ): array {
        return [
            'mode' => $mode,
            'window' => ['start' => $windowStart->toDateTimeString(), 'end' => $windowEnd->toDateTimeString()],
            'phase' => $phase,
            'duplicate_check_summary' => $duplicateCheckSummary,
            'transaction_taxes_null_count' => $nullCount,
            'integrity_report' => $integrityReport,
            'persisted' => $captureId !== null,
            'capture_id' => $captureId,
        ];
    }

    protected function render(array $result): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info(sprintf(
            'Integrity evidence capture (%s) — window %s to %s — phase: %s',
            $result['mode'],
            $result['window']['start'],
            $result['window']['end'],
            $result['phase']
        ));

        $this->table(
            ['Duplicate groups', 'Duplicate rows', 'transaction_pk IS NULL count'],
            [[
                $result['duplicate_check_summary']['total_duplicate_groups'],
                $result['duplicate_check_summary']['total_duplicate_rows'],
                $result['transaction_taxes_null_count'],
            ]]
        );

        $this->line('--- txn:pk-integrity report ---');
        $this->line($result['integrity_report']);

        if ($result['persisted']) {
            $this->line("Persisted as capture #{$result['capture_id']}.");
        } else {
            $this->line('Dry run — nothing was written.');
        }
    }
}
