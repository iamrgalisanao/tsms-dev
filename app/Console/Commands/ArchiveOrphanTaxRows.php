<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backfill\IdleTransactionWatchdog;
use App\Services\Backfill\OrphanTaxArchiver;
use App\Services\Backfill\OrphanTaxDeleter;
use App\Services\Backfill\OrphanTaxReconciler;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 002-backfill-transaction-taxes — orphan archive/reconcile/delete pipeline.
 * Slice 12 (T068) built `--phase=archive`; Slice 13 (T069/T071-partial) added
 * `--phase=reconcile`; Slice 14 (T070/T070a/T071/T079) adds `--phase=delete`
 * — see specs/002-backfill-transaction-taxes/slice-12-orphan-archive-brief.md,
 * .../slice-13-orphan-reconcile-brief.md, and
 * .../slice-14-orphan-delete-brief.md.
 *
 * `archive`, `reconcile`, and `delete` are all implemented in this build.
 * `--phase` has no default (corrected 2026-08-12 — a defaulted
 * `--phase=archive` was only ever a deliberate, temporary concession while
 * `delete` didn't exist yet; now that all three phases do, an operator must
 * state intent explicitly on every invocation). Any missing, empty, or
 * otherwise invalid `--phase` value is rejected outright, before any DB
 * access at all. `delete` is Stage 3 — the only phase that ever issues a DELETE
 * against a live table — gated by OrphanTaxDeleter's authorization-token
 * mechanism (T079): a dry-run (no `--apply`) reports the current evidence
 * hash and a preview count without writing anything; `--apply` requires a
 * non-empty `--token=` matching that hash, validated before any DB access.
 *
 * Each phase keeps its own buildResult()/render() pair (buildResult()/
 * render() for archive, buildReconcileResult()/renderReconcile() for
 * reconcile, buildDeleteResult()/renderDelete() for delete) — the phases
 * have entirely different result shapes, but each individually follows this
 * feature's established one-result-object convention (see
 * BackfillTransactionTaxes's own buildResult()/render() split): a single
 * array drives both human and `--json` output for that phase, so the two
 * representations can never drift apart.
 */
class ArchiveOrphanTaxRows extends Command
{
    protected const DEFAULT_CHUNK_SIZE = 1000;

    protected $signature = 'transactions:archive-orphan-taxes
        {--phase= : Required. One of: archive, reconcile, delete.}
        {--apply : Persist. Without this flag, dry-run only: report counts/verdict/preview, write nothing.}
        {--chunk=1000 : Archive phase\'s insert chunk size, and delete phase\'s DELETE chunk size.}
        {--day= : Single day (Y-m-d). Required when --phase=reconcile or --phase=delete.}
        {--token= : Required when --phase=delete --apply is set. Must equal the authorization_token preflight() reports for this day.}
        {--json}';

    protected $description = 'Archive orphaned (transaction_pk IS NULL) transaction_taxes rows into transaction_taxes_orphan_archive, reconcile a day\'s already-archived orphans against reconstructed replacements, and delete a day\'s archived+reconciled orphans from the live table under a token-gated authorization check';

    public function handle(OrphanTaxArchiver $archiver, OrphanTaxReconciler $reconciler, OrphanTaxDeleter $deleter, IdleTransactionWatchdog $watchdog): int
    {
        $phaseOption = $this->option('phase');

        // --phase has no default (corrected 2026-08-12 — now that archive,
        // reconcile, AND delete all exist, an operator must state intent
        // explicitly on every invocation; a defaulted phase was only ever a
        // deliberate, temporary concession while delete didn't exist yet).
        // Rejected before any other option is even validated, let alone any
        // DB access.
        if ($phaseOption === null || $phaseOption === '') {
            $this->error('--phase is required — must be one of: archive, reconcile, delete.');

            return self::FAILURE;
        }

        $phase = (string) $phaseOption;

        if (! in_array($phase, ['archive', 'reconcile', 'delete'], true)) {
            $this->error("--phase={$phase} is invalid — must be one of: archive, reconcile, delete.");

            return self::FAILURE;
        }

        if ($phase === 'reconcile') {
            return $this->handleReconcile($reconciler, $watchdog);
        }

        if ($phase === 'delete') {
            return $this->handleDelete($deleter, $watchdog);
        }

        return $this->handleArchive($archiver, $watchdog);
    }

    /**
     * Slice 17 (T100): operational safety gate, checked only when --apply is
     * set (a dry-run never mutates anything, so there is nothing for the
     * gate to protect). On failure, refuses before OrphanTaxArchiver is ever
     * invoked — zero DB access beyond the check itself, so `$summary` is a
     * zeroed placeholder rather than a real (extra, read-only) dry-run
     * query. On success, applies the low lock-wait timeout and takes the
     * "before" processlist snapshot.
     */
    protected function handleArchive(OrphanTaxArchiver $archiver, IdleTransactionWatchdog $watchdog): int
    {
        $chunkValidationError = $this->validateChunkOption();

        if ($chunkValidationError !== null) {
            $this->error($chunkValidationError);

            return self::FAILURE;
        }

        $chunkSize = (int) $this->option('chunk');
        $apply = (bool) $this->option('apply');

        $operationalSafety = null;

        if ($apply) {
            $gate = $watchdog->check();
            $operationalSafety = $this->pendingOperationalSafetyEnvelope($gate);

            if (! $gate['passed']) {
                $result = $this->buildResult('archive', $apply, $chunkSize, $this->emptyArchiveSummary(), $operationalSafety);
                $this->render($result);

                return self::FAILURE;
            }

            $watchdog->applyLowLockWaitTimeout();
            $operationalSafety['processlist_before'] = $watchdog->sampleProcesslist();

            $summary = $archiver->archive($chunkSize);

            $operationalSafety['processlist_after'] = $watchdog->sampleProcesslist();
        } else {
            // Dry-run deliberately never touches OrphanTaxArchiver or the
            // watchdog — counts come entirely from read-only queries below,
            // and zero rows are written.
            $summary = $this->dryRunSummary();
        }

        $result = $this->buildResult('archive', $apply, $chunkSize, $summary, $operationalSafety);

        $this->render($result);

        return self::SUCCESS;
    }

    /**
     * @return array{gate: array, processlist_before: null, processlist_after: null}
     */
    protected function pendingOperationalSafetyEnvelope(array $gate): array
    {
        return [
            'gate' => $gate,
            'processlist_before' => null,
            'processlist_after' => null,
        ];
    }

    /**
     * @return array{processed: int, newly_archived: int, already_archived: int}
     */
    protected function emptyArchiveSummary(): array
    {
        return [
            'processed' => 0,
            'newly_archived' => 0,
            'already_archived' => 0,
        ];
    }

    /**
     * `--phase=reconcile` wiring (T069's CLI half). `--day` is required
     * (cli-contract.md: `--day` is required for reconcile/delete, not
     * archive). evaluate() always runs first, read-only, regardless of
     * `--apply` — it is the single source of truth for the day's verdict.
     * `--apply` then calls persist() only when the evaluation passed;
     * OrphanTaxReconciler::persist() itself refuses to write anything for a
     * halted/refused day, so this method's own guard is a second,
     * belt-and-braces check rather than the only thing preventing a bad
     * write.
     */
    protected function handleReconcile(OrphanTaxReconciler $reconciler, IdleTransactionWatchdog $watchdog): int
    {
        $dayOption = $this->option('day');

        if ($dayOption === null || $dayOption === '') {
            $this->error('--day is required when --phase=reconcile.');

            return self::FAILURE;
        }

        if (! $this->isValidDate((string) $dayOption)) {
            $this->error("Invalid --day value '{$dayOption}': expected format Y-m-d.");

            return self::FAILURE;
        }

        $day = Carbon::createFromFormat('Y-m-d', (string) $dayOption)->startOfDay();
        $apply = (bool) $this->option('apply');

        // evaluate() always runs first, read-only, regardless of --apply or
        // the watchdog gate below — it is the single source of truth for the
        // day's verdict and this brief changes nothing about it.
        $evaluation = $reconciler->evaluate($day);

        $persisted = false;
        $operationalSafety = null;

        if ($apply) {
            // Slice 17 (T100): checked before persist() (the only mutating
            // statement in this branch) is ever invoked. A gate failure
            // refuses persist() outright, even if the day's own evaluation
            // passed — evaluate()'s own passed/halt_reason fields are never
            // altered by this gate, only whether persist() actually runs.
            $gate = $watchdog->check();
            $operationalSafety = $this->pendingOperationalSafetyEnvelope($gate);

            if (! $gate['passed']) {
                $result = $this->buildReconcileResult((string) $dayOption, $apply, $persisted, $evaluation, $operationalSafety);
                $this->renderReconcile($result);

                return self::FAILURE;
            }

            $watchdog->applyLowLockWaitTimeout();
            $operationalSafety['processlist_before'] = $watchdog->sampleProcesslist();

            if ($evaluation['passed']) {
                $reconciler->persist($evaluation);
                $persisted = true;
            }

            $operationalSafety['processlist_after'] = $watchdog->sampleProcesslist();
        }

        $result = $this->buildReconcileResult((string) $dayOption, $apply, $persisted, $evaluation, $operationalSafety);

        $this->renderReconcile($result);

        return $evaluation['passed'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * `--phase=delete` wiring (T070/T079's CLI half). `--day` is required,
     * same as reconcile. Dry-run (no `--apply`) calls
     * OrphanTaxDeleter::preflight() only — precondition status, the current
     * evidence hash (`authorization_token`), and a delete-preview count,
     * nothing written. `--apply` requires a non-empty `--token=`, rejected
     * before any DB access if missing or empty (mirroring this command's
     * existing validate-before-DB-access convention), then calls delete(),
     * which itself re-verifies both preconditions and the token fresh —
     * this method's own token-presence guard is a second, belt-and-braces
     * check rather than the only thing preventing an unauthorized delete.
     */
    protected function handleDelete(OrphanTaxDeleter $deleter, IdleTransactionWatchdog $watchdog): int
    {
        $dayOption = $this->option('day');

        if ($dayOption === null || $dayOption === '') {
            $this->error('--day is required when --phase=delete.');

            return self::FAILURE;
        }

        if (! $this->isValidDate((string) $dayOption)) {
            $this->error("Invalid --day value '{$dayOption}': expected format Y-m-d.");

            return self::FAILURE;
        }

        $chunkValidationError = $this->validateChunkOption();

        if ($chunkValidationError !== null) {
            $this->error($chunkValidationError);

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $token = (string) ($this->option('token') ?? '');

        if ($apply && $token === '') {
            $this->error('--token is required when --phase=delete --apply is set.');

            return self::FAILURE;
        }

        $day = Carbon::createFromFormat('Y-m-d', (string) $dayOption)->startOfDay();
        $chunkSize = (int) $this->option('chunk');

        $operationalSafety = null;

        if ($apply) {
            // Slice 17 (T100): checked before delete() (the only mutating
            // statement in this branch) is ever invoked.
            $gate = $watchdog->check();
            $operationalSafety = $this->pendingOperationalSafetyEnvelope($gate);

            if (! $gate['passed']) {
                $outcome = $this->operationalSafetyRefusalDeleteOutcome();
                $result = $this->buildDeleteResult((string) $dayOption, $apply, $outcome, $operationalSafety);
                $this->renderDelete($result);

                return self::FAILURE;
            }

            $watchdog->applyLowLockWaitTimeout();
            $operationalSafety['processlist_before'] = $watchdog->sampleProcesslist();

            $outcome = $deleter->delete($day, $token, $chunkSize);
            $passed = $outcome['authorized'];

            $operationalSafety['processlist_after'] = $watchdog->sampleProcesslist();
        } else {
            $outcome = $deleter->preflight($day);
            $passed = $outcome['passed'];
        }

        $result = $this->buildDeleteResult((string) $dayOption, $apply, $outcome, $operationalSafety);

        $this->renderDelete($result);

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Synthetic OrphanTaxDeleter::delete()-shaped outcome for the case where
     * this command's own watchdog gate refuses before OrphanTaxDeleter is
     * ever invoked — deliberately never one of OrphanTaxDeleter's own
     * REFUSAL_* constants (that class is never called on this path at all),
     * kept CLI-layer-only so OrphanTaxDeleter itself stays untouched by this
     * brief.
     *
     * @return array{day: null, authorized: bool, refusal_reason: string, refusal_message: string, token_verified: null, chunks_processed: int, rows_deleted: int, already_deleted: int}
     */
    protected function operationalSafetyRefusalDeleteOutcome(): array
    {
        return [
            'day' => null,
            'authorized' => false,
            'refusal_reason' => 'operational_safety_gate_failed',
            'refusal_message' => 'Delete refused: the operational safety gate detected an idle transaction older than the configured threshold.',
            'token_verified' => null,
            'chunks_processed' => 0,
            'rows_deleted' => 0,
            'already_deleted' => 0,
        ];
    }

    protected function isValidDate(string $value): bool
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
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
     * @param  array{gate: array, processlist_before: array|null, processlist_after: array|null}|null  $operationalSafety  Slice 17 (T100) addition — null for a dry-run (the watchdog is never checked then), populated for every --apply invocation.
     * @return array{
     *     phase: string,
     *     applied: bool,
     *     chunk_size: int,
     *     totals: array{processed: int, newly_archived: int, already_archived: int},
     *     operational_safety: array|null,
     * }
     */
    protected function buildResult(string $phase, bool $applied, int $chunkSize, array $summary, ?array $operationalSafety = null): array
    {
        return [
            'phase' => $phase,
            'applied' => $applied,
            'chunk_size' => $chunkSize,
            'totals' => $summary,
            'operational_safety' => $operationalSafety,
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

    /**
     * One result array drives both the human table and --json output for
     * `--phase=reconcile`, same convention as archive's buildResult()/
     * render() pair above, just for reconcile's own (differently-shaped)
     * result.
     *
     *
     * @param  array{gate: array, processlist_before: array|null, processlist_after: array|null}|null  $operationalSafety  Slice 17 (T100) addition — null for a dry-run, populated for every --apply invocation.
     * @return array{
     *     phase: string,
     *     day: string,
     *     applied: bool,
     *     persisted: bool,
     *     passed: bool,
     *     halt_reason: string|null,
     *     halt_message: string|null,
     *     precondition: array{passed: bool, pending_count: int},
     *     totals: array{orphans: int, inserted: int, reconciled: int, timestamp_out_of_tolerance: int, no_replacement_exists: int, orphan_content_mismatch: int},
     *     content_gap: array{actual: int, expected: int|null, ratio: int|null, missing_payload_count: int|null, total_affected_transactions: int|null},
     *     operational_safety: array|null,
     * }
     */
    protected function buildReconcileResult(string $day, bool $applied, bool $persisted, array $evaluation, ?array $operationalSafety = null): array
    {
        return [
            'phase' => 'reconcile',
            'day' => $day,
            'applied' => $applied,
            'persisted' => $persisted,
            'passed' => $evaluation['passed'],
            'halt_reason' => $evaluation['halt_reason'],
            'halt_message' => $evaluation['halt_message'],
            'precondition' => $evaluation['precondition'],
            'totals' => $evaluation['totals'],
            'content_gap' => $evaluation['content_gap'],
            'operational_safety' => $operationalSafety,
        ];
    }

    protected function renderReconcile(array $result): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info(sprintf(
            'Orphan tax reconcile — day: %s (%s) — %s',
            $result['day'],
            $result['applied'] ? 'apply' : 'dry-run',
            $result['passed'] ? 'PASSED' : 'HALTED'
        ));

        if ($result['halt_message'] !== null) {
            $this->warn($result['halt_message']);
        }

        $this->table(
            ['Reconciled', 'Timestamp out of tolerance', 'No replacement exists', 'Orphan content mismatch'],
            [[
                $result['totals']['reconciled'],
                $result['totals']['timestamp_out_of_tolerance'],
                $result['totals']['no_replacement_exists'],
                $result['totals']['orphan_content_mismatch'],
            ]]
        );

        $this->line(sprintf(
            'Persisted to archive: %s',
            $result['persisted'] ? 'yes' : 'no'
        ));
    }

    /**
     * One result array drives both the human table and --json output for
     * `--phase=delete`, same convention as archive/reconcile's own
     * buildResult()/render() pairs. Dry-run and --apply have different
     * underlying shapes (OrphanTaxDeleter::preflight() vs. ::delete()), so
     * fields that don't apply to the current mode are reported as null
     * rather than omitted — keeping the result's key set stable across both
     * modes for --json consumers.
     *
     *
     * @param  array{gate: array, processlist_before: array|null, processlist_after: array|null}|null  $operationalSafety  Slice 17 (T100) addition — null for a dry-run, populated for every --apply invocation.
     * @return array{
     *     phase: string,
     *     day: string,
     *     applied: bool,
     *     passed: bool,
     *     refusal_reason: string|null,
     *     refusal_message: string|null,
     *     preconditions: array|null,
     *     authorization_token: string|null,
     *     preview_count: int|null,
     *     token_verified: bool|null,
     *     chunks_processed: int|null,
     *     rows_deleted: int|null,
     *     already_deleted: int|null,
     *     operational_safety: array|null,
     * }
     */
    protected function buildDeleteResult(string $day, bool $applied, array $outcome, ?array $operationalSafety = null): array
    {
        if (! $applied) {
            return [
                'phase' => 'delete',
                'day' => $day,
                'applied' => false,
                'passed' => $outcome['passed'],
                'refusal_reason' => $outcome['refusal_reason'],
                'refusal_message' => $outcome['refusal_message'],
                'preconditions' => $outcome['preconditions'],
                'authorization_token' => $outcome['authorization_token'],
                'preview_count' => $outcome['preview_count'],
                'token_verified' => null,
                'chunks_processed' => null,
                'rows_deleted' => null,
                'already_deleted' => null,
                'operational_safety' => $operationalSafety,
            ];
        }

        return [
            'phase' => 'delete',
            'day' => $day,
            'applied' => true,
            'passed' => $outcome['authorized'],
            'refusal_reason' => $outcome['refusal_reason'],
            'refusal_message' => $outcome['refusal_message'],
            'preconditions' => null,
            'authorization_token' => null,
            'preview_count' => null,
            'token_verified' => $outcome['token_verified'],
            'chunks_processed' => $outcome['chunks_processed'],
            'rows_deleted' => $outcome['rows_deleted'],
            'already_deleted' => $outcome['already_deleted'],
            'operational_safety' => $operationalSafety,
        ];
    }

    protected function renderDelete(array $result): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info(sprintf(
            'Orphan tax delete — day: %s (%s) — %s',
            $result['day'],
            $result['applied'] ? 'apply' : 'dry-run',
            $result['passed'] ? ($result['applied'] ? 'DELETED' : 'READY') : 'REFUSED'
        ));

        if ($result['refusal_message'] !== null) {
            $this->warn($result['refusal_message']);
        }

        if (! $result['applied']) {
            if ($result['authorization_token'] !== null) {
                $this->line("authorization_token: {$result['authorization_token']}");
            }

            $this->table(
                ['Preview count'],
                [[$result['preview_count'] ?? 0]]
            );
        } else {
            $this->table(
                ['Chunks processed', 'Rows deleted', 'Already deleted'],
                [[
                    $result['chunks_processed'] ?? 0,
                    $result['rows_deleted'] ?? 0,
                    $result['already_deleted'] ?? 0,
                ]]
            );
        }
    }
}
