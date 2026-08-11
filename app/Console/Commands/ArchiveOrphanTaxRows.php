<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backfill\OrphanTaxArchiver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 002-backfill-transaction-taxes, Slice 12 (T068, archive-phase only) —
 * Stage 1 of 3 for the orphan archive/reconcile/delete pipeline. See
 * specs/002-backfill-transaction-taxes/slice-12-orphan-archive-brief.md.
 *
 * Only `--phase=archive` is implemented in this build. Any other value
 * (`reconcile`, `delete`, empty, or garbage) is rejected outright, before
 * any DB access at all — mirroring how Slice 4's BackfillTransactionTaxes
 * rejected `--apply` outright before Slice 8 implemented it.
 *
 * Dry-run (no `--apply`) never instantiates/calls OrphanTaxArchiver's write
 * path at all — it only reports counts derived from read-only queries.
 * `--apply` runs OrphanTaxArchiver::archive() and reports what actually
 * happened. One buildResult() method feeds both human and `--json` output,
 * this feature's established convention (see BackfillTransactionTaxes's own
 * buildResult()/render() split).
 */
class ArchiveOrphanTaxRows extends Command
{
    protected const DEFAULT_CHUNK_SIZE = 1000;

    protected $signature = 'transactions:archive-orphan-taxes
        {--phase=archive : Only \'archive\' is implemented in this build. \'reconcile\'/\'delete\' are rejected.}
        {--apply : Persist. Without this flag, dry-run only: report counts, write nothing.}
        {--chunk=1000}
        {--json}';

    protected $description = 'Archive orphaned (transaction_pk IS NULL) transaction_taxes rows into transaction_taxes_orphan_archive (archive phase only in this build)';

    public function handle(OrphanTaxArchiver $archiver): int
    {
        $phase = (string) $this->option('phase');

        // Rejected before any other option is even validated, let alone any
        // DB access — matches this slice's hard scope boundary that
        // reconcile/delete simply don't exist yet in this build.
        if ($phase !== 'archive') {
            $this->error("--phase={$phase} is not yet implemented in this build — reconcile/delete are separate, later slices.");

            return self::FAILURE;
        }

        $chunkValidationError = $this->validateChunkOption();

        if ($chunkValidationError !== null) {
            $this->error($chunkValidationError);

            return self::FAILURE;
        }

        $chunkSize = (int) $this->option('chunk');
        $apply = (bool) $this->option('apply');

        // Dry-run deliberately never touches OrphanTaxArchiver — counts come
        // entirely from read-only queries below, and zero rows are written.
        $summary = $apply ? $archiver->archive($chunkSize) : $this->dryRunSummary();

        $result = $this->buildResult($phase, $apply, $chunkSize, $summary);

        $this->render($result);

        return self::SUCCESS;
    }

    protected function validateChunkOption(): ?string
    {
        $chunk = $this->option('chunk');

        if (! ctype_digit((string) $chunk) || (int) $chunk <= 0) {
            return "Invalid --chunk value '{$chunk}': must be a positive integer.";
        }

        return null;
    }

    /**
     * Read-only equivalent of OrphanTaxArchiver::archive()'s return shape,
     * computed without writing anything: total current orphans, how many
     * already have a matching archive row (joined on original_id), and how
     * many would be newly archived (the difference).
     *
     * @return array{processed: int, newly_archived: int, already_archived: int}
     */
    protected function dryRunSummary(): array
    {
        $totalOrphans = DB::table('transaction_taxes')
            ->whereNull('transaction_pk')
            ->count();

        $alreadyArchived = DB::table('transaction_taxes as t')
            ->join('transaction_taxes_orphan_archive as a', 'a.original_id', '=', 't.id')
            ->whereNull('t.transaction_pk')
            ->count();

        return [
            'processed' => $totalOrphans,
            'newly_archived' => $totalOrphans - $alreadyArchived,
            'already_archived' => $alreadyArchived,
        ];
    }

    /**
     * One result array drives both the human table and --json output — no
     * separate/duplicated formatting logic that could drift between them.
     *
     * @param  array{processed: int, newly_archived: int, already_archived: int}  $summary
     * @return array{
     *     phase: string,
     *     applied: bool,
     *     chunk_size: int,
     *     totals: array{processed: int, newly_archived: int, already_archived: int},
     * }
     */
    protected function buildResult(string $phase, bool $applied, int $chunkSize, array $summary): array
    {
        return [
            'phase' => $phase,
            'applied' => $applied,
            'chunk_size' => $chunkSize,
            'totals' => $summary,
        ];
    }

    protected function render(array $result): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info(sprintf(
            'Orphan tax archive — phase: %s (%s, chunk size %d)',
            $result['phase'],
            $result['applied'] ? 'apply' : 'dry-run',
            $result['chunk_size']
        ));

        $this->table(
            ['Processed', 'Newly archived', 'Already archived'],
            [[
                $result['totals']['processed'],
                $result['totals']['newly_archived'],
                $result['totals']['already_archived'],
            ]]
        );
    }
}
