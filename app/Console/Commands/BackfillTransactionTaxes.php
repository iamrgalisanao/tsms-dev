<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TaxBackfillRecord;
use App\Models\TaxBackfillRun;
use App\Services\Backfill\TaxBackfillOutcome;
use App\Services\Backfill\TaxBackfillRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 002-backfill-transaction-taxes, Slice 4 (T017-T022) + Slice 8 (--apply
 * wiring, per specs/002-backfill-transaction-taxes/slice-8-cli-apply-wiring-brief.md).
 *
 * Dry-run (--from/--to or --day, no --apply) drives TaxBackfillRunner::dryRun()
 * exactly as it did in Slice 4 — unchanged. --apply now drives
 * TaxBackfillRunner::apply() instead of being rejected outright, but only
 * when --day (a single day, never a --from/--to window) is given —
 * validateInput() enforces this before the runner is ever touched.
 *
 * Slice 9 (T097) closed the gap Slice 8 disclosed: TaxBackfillRunner::apply()
 * now runs a schema pre-flight (idx_tx_taxes_pk, fk_tx_taxes_pk + its ON
 * DELETE action, transaction_pk nullability) before scanning a single
 * transaction. A failed pre-flight ends the run in
 * TaxBackfillRun::STATUS_PREFLIGHT_FAILED (exit code self::EXIT_PREFLIGHT_FAILED
 * below) with zero transactions touched — surfaced loudly in both human and
 * --json output via the `preflight_checks` result. dryRun() never runs this
 * check.
 *
 * Ergonomics mirror App\Console\Commands\LicenseBindingBackfillCommand per
 * specs/002-backfill-transaction-taxes/contracts/cli-contract.md (research
 * R7): dry-run-by-default, a single result array driving both human and
 * --json output, and a summary table for the human path.
 */
class BackfillTransactionTaxes extends Command
{
    /**
     * Distinct non-zero exit codes for STATUS_INTERRUPTED/STATUS_STOPPED, so
     * a calling script can tell "the operator meant to stop this" apart from
     * "something broke" without parsing output text (Slice 8 brief, decision
     * 3). self::SUCCESS (0) and self::FAILURE (1) — inherited from
     * Illuminate\Console\Command — cover the completed-clean and
     * failed/completed-with-failures cases respectively.
     */
    protected const EXIT_INTERRUPTED = 2;

    protected const EXIT_STOPPED = 3;

    /**
     * Slice 9 (T097): a schema pre-flight check failed before any
     * transaction was scanned — distinct from both self::FAILURE (a
     * processing error) and self::EXIT_INTERRUPTED, so a calling script can
     * tell "the schema isn't safe to run against" apart from either. Only
     * ever reachable via --apply; dry-run never runs the pre-flight check.
     */
    protected const EXIT_PREFLIGHT_FAILED = 4;

    /**
     * Conservative default throttle (ms) applied between chunks for a real
     * --apply run when --throttle isn't given explicitly. Irrelevant to
     * dry-run. Chosen per the Slice 8 brief as a middle ground: enough to
     * yield to live traffic without making a large day's worth of
     * transactions impractically slow to apply.
     */
    protected const DEFAULT_APPLY_THROTTLE_MS = 500;

    protected $signature = 'transactions:backfill-taxes
        {--from= : Window start (Y-m-d). Required unless --day is given. Not permitted with --apply.}
        {--to= : Window end (Y-m-d, inclusive). Required unless --day is given. Not permitted with --apply.}
        {--day= : Single day (Y-m-d). Shorthand for --from/--to over that one day. Required when --apply is given.}
        {--tenant=* : Restrict to one or more tenant ids. Repeatable, e.g. --tenant=1 --tenant=2.}
        {--apply : Persist changes. Requires --day (single-day only — --from/--to whole-window apply is not permitted). A schema pre-flight check runs automatically before any write: verifies idx_tx_taxes_pk and fk_tx_taxes_pk are present on transaction_taxes, and fails the run before touching any transaction if either is missing.}
        {--chunk=500 : Transactions per chunk.}
        {--limit= : Stop after N transactions scanned (piloting).}
        {--throttle= : Inter-chunk delay in milliseconds for --apply. Defaults to 500 when --apply is given and this is omitted. Ignored for dry-run.}
        {--kill-switch-path= : Path to a sentinel file; if it exists, an --apply run stops cleanly before its next chunk. Optional, no default. Ignored for dry-run.}
        {--json : Emit machine-readable JSON instead of a summary table.}';

    protected $description = 'Reconstruct missing transaction_taxes rows: dry-run by default (--from/--to or --day), or persist via --apply (--day only)';

    public function handle(TaxBackfillRunner $runner): int
    {
        $validationError = $this->validateInput();

        if ($validationError !== null) {
            $this->error($validationError);

            return self::FAILURE;
        }

        $tenantIds = $this->resolveTenantIds();
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $chunk = (int) $this->option('chunk');

        if ($this->option('apply')) {
            // validateInput() guarantees --day is present and --from/--to
            // are absent whenever --apply is set, so this is always exactly
            // one resolved day, never a window.
            $day = Carbon::createFromFormat('Y-m-d', $this->option('day'));

            $throttleMs = $this->option('throttle') !== null
                ? (int) $this->option('throttle')
                : self::DEFAULT_APPLY_THROTTLE_MS;

            $killSwitchPath = $this->option('kill-switch-path');
            $killSwitchPath = ($killSwitchPath !== null && $killSwitchPath !== '') ? $killSwitchPath : null;

            $run = $runner->apply($day, $tenantIds, $limit, $chunk, $throttleMs, $killSwitchPath);
        } else {
            // Dry-run path, byte-for-byte what Slice 4 shipped: --throttle/
            // --kill-switch-path are simply never read here if an operator
            // mistakenly passes them on a dry run.
            [$from, $to] = $this->resolveWindow();

            $run = $runner->dryRun($from, $to, $tenantIds, $limit, $chunk);
        }

        $result = $this->buildResult($run);

        $this->render($result);

        return $this->exitCodeFor($result);
    }

    /**
     * Six possible TaxBackfillRun statuses, mapped to distinct exit codes
     * (Slice 8 brief, decision 3; Slice 9 brief for preflight_failed): 0 =
     * completed with zero failures, 1 = failed (or completed with
     * failed_count > 0), 2 = interrupted, 3 = stopped (deliberate
     * kill-switch stop, never conflated with failed), 4 = preflight_failed
     * (schema pre-flight rejected the run before any transaction was
     * scanned). Applies identically to dry-run and apply for the first four
     * — a dry run can also end interrupted/failed in principle, just never
     * stopped or preflight_failed (dry-run never takes a kill-switch path
     * and never runs the pre-flight check).
     */
    protected function exitCodeFor(array $result): int
    {
        return match (true) {
            $result['status'] === TaxBackfillRun::STATUS_PREFLIGHT_FAILED => self::EXIT_PREFLIGHT_FAILED,
            $result['status'] === TaxBackfillRun::STATUS_STOPPED => self::EXIT_STOPPED,
            $result['status'] === TaxBackfillRun::STATUS_INTERRUPTED => self::EXIT_INTERRUPTED,
            $result['status'] === TaxBackfillRun::STATUS_FAILED => self::FAILURE,
            $result['status'] === TaxBackfillRun::STATUS_COMPLETED && $result['totals']['failed'] > 0 => self::FAILURE,
            $result['status'] === TaxBackfillRun::STATUS_COMPLETED => self::SUCCESS,
            default => self::FAILURE,
        };
    }

    /**
     * Fail fast, before the runner (or any DB write beyond the option
     * parsing itself) ever executes. Returns a human-readable error message,
     * or null when input is valid.
     */
    protected function validateInput(): ?string
    {
        $day = $this->option('day');
        $from = $this->option('from');
        $to = $this->option('to');
        $apply = (bool) $this->option('apply');

        $hasDay = $day !== null && $day !== '';
        $hasFrom = $from !== null && $from !== '';
        $hasTo = $to !== null && $to !== '';

        // --apply checks come first, before any other validation, once we
        // know --apply is set — this structurally forbids whole-window
        // apply: there is no code path where --apply reaches the runner
        // without a single resolved --day. Dry-run's existing --day-or-
        // --from/--to flexibility below is completely unchanged when --apply
        // is not set.
        if ($apply && ($hasFrom || $hasTo)) {
            return '--apply requires --day (a single day) — --from/--to whole-window apply is not permitted.';
        }

        if ($apply && ! $hasDay) {
            return '--apply requires --day.';
        }

        if ($hasDay && ($hasFrom || $hasTo)) {
            return 'Provide either --day, or --from and --to — not both forms.';
        }

        if (! $hasDay && ! ($hasFrom && $hasTo)) {
            return 'Provide either --day, or both --from and --to.';
        }

        if ($hasDay && ! $this->isValidDate($day)) {
            return "Invalid --day value '{$day}': expected format Y-m-d.";
        }

        if (! $hasDay) {
            if (! $this->isValidDate($from)) {
                return "Invalid --from value '{$from}': expected format Y-m-d.";
            }

            if (! $this->isValidDate($to)) {
                return "Invalid --to value '{$to}': expected format Y-m-d.";
            }

            if (Carbon::createFromFormat('Y-m-d', $to)->lt(Carbon::createFromFormat('Y-m-d', $from))) {
                return "--to ({$to}) must not be before --from ({$from}).";
            }

            // --to is exclusive (resolveWindow() resolves it to one second
            // before its own midnight), so --from == --to — or any pair
            // that resolves to an inverted range — silently produces an
            // empty window rather than a real request. Reuse
            // resolveWindow() itself for this check so the validation can
            // never drift from the actual window logic it's guarding.
            [$windowStart, $windowEnd] = $this->resolveWindow();

            if ($windowEnd->lt($windowStart)) {
                return "--from ({$from}) and --to ({$to}) resolve to an empty window (did you mean --day instead?).";
            }
        }

        foreach ((array) $this->option('tenant') as $tenant) {
            if (! $this->isPositiveInteger($tenant)) {
                return "Invalid --tenant value '{$tenant}': must be an integer.";
            }
        }

        if (! $this->isPositiveInteger($this->option('chunk'))) {
            return "Invalid --chunk value '{$this->option('chunk')}': must be a positive integer.";
        }

        if ($this->option('limit') !== null && ! $this->isPositiveInteger($this->option('limit'))) {
            return "Invalid --limit value '{$this->option('limit')}': must be a positive integer.";
        }

        // Code review finding (Slice 8): --throttle silently (int)-casts a
        // malformed value ("5oo" -> 5, "abc" -> 0) with no operator
        // feedback, which could turn the conservative 500ms default's
        // intended safety margin into a near-zero throttle by typo. Validate
        // it exactly like --chunk/--limit — including rejecting 0, which
        // would defeat the point of throttling the same way --chunk=0 does.
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
     * `--day` is an inclusive single-day window (00:00:00 to 23:59:59, per
     * this repo's ReconcileSubmissionsCommand startOfDay()/endOfDay()
     * precedent). `--from`/`--to` follow the CLI contract's own wording —
     * `--to` is documented as *exclusive* — so the resolved end bound is one
     * second before `--to`'s midnight, not `--to`'s own end-of-day. This
     * keeps `--from=2026-06-13 --to=2026-08-10` (quickstart.md) matching the
     * defect window's actual last day, 2026-08-09, rather than reaching one
     * day further than intended. TaxBackfillRunner::dryRun()'s underlying
     * whereBetween() is inclusive on both ends, so exclusivity has to be
     * expressed here, in how the bound is computed.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveWindow(): array
    {
        $day = $this->option('day');

        if ($day !== null && $day !== '') {
            return [
                Carbon::createFromFormat('Y-m-d', $day)->startOfDay(),
                Carbon::createFromFormat('Y-m-d', $day)->endOfDay(),
            ];
        }

        return [
            Carbon::createFromFormat('Y-m-d', $this->option('from'))->startOfDay(),
            Carbon::createFromFormat('Y-m-d', $this->option('to'))->startOfDay()->subSecond(),
        ];
    }

    /**
     * @return array<int>|null
     */
    protected function resolveTenantIds(): ?array
    {
        $tenants = array_values(array_filter(
            (array) $this->option('tenant'),
            fn ($tenant) => $tenant !== null && $tenant !== ''
        ));

        if ($tenants === []) {
            return null;
        }

        return array_map('intval', $tenants);
    }

    /**
     * One result array drives both the human table and --json output — no
     * separate/duplicated formatting logic that could drift between them.
     *
     * @return array{
     *     run_id: int,
     *     status: string,
     *     mode: string,
     *     window: array{start: string, end: string},
     *     tenant_ids: array<int>|null,
     *     totals: array{scanned: int, reconstructed: int, skipped_existing: int, quarantined: int, failed: int},
     *     per_tenant: list<array<string, int>>,
     *     per_day: list<array<string, int|string>>,
     *     preflight_checks: array{index_present: bool, fk_present: bool, fk_on_delete_action: string|null, transaction_pk_nullable: bool, passed: bool}|null,
     * }
     */
    protected function buildResult(TaxBackfillRun $run): array
    {
        return [
            'run_id' => $run->id,
            'status' => $run->status,
            'mode' => $run->mode,
            'window' => [
                'start' => $run->window_start->toDateTimeString(),
                'end' => $run->window_end->toDateTimeString(),
            ],
            'tenant_ids' => $this->resolveTenantIds(),
            'totals' => [
                'scanned' => $run->scanned_count,
                'reconstructed' => $run->reconstructed_count,
                'skipped_existing' => $run->skipped_existing_count,
                'quarantined' => $run->quarantined_count,
                'failed' => $run->failed_count,
            ],
            'per_tenant' => $this->breakdownByTenant($run),
            'per_day' => $this->breakdownByDay($run),
            // Slice 9 (T097): null for dry-run rows (dryRun() never runs the
            // pre-flight check) and for any legacy apply row created before
            // this column existed.
            'preflight_checks' => $run->preflight_checks,
        ];
    }

    /**
     * Read-only aggregation over this run's TaxBackfillRecord rows, grouped
     * by tenant. The runner itself doesn't track this — it's derived here
     * purely for operator-facing reporting.
     */
    protected function breakdownByTenant(TaxBackfillRun $run): array
    {
        $rows = TaxBackfillRecord::query()
            ->select('tenant_id', 'outcome', DB::raw('COUNT(*) as outcome_count'))
            ->where('run_id', $run->id)
            ->groupBy('tenant_id', 'outcome')
            ->get();

        return $this->pivotByOutcome($rows, 'tenant_id');
    }

    /**
     * Same shape as breakdownByTenant(), grouped by the scanned
     * transaction's calendar date instead — joins back to `transactions` via
     * `transaction_pk` to get at `created_at`, since TaxBackfillRecord
     * itself doesn't store a date column. Lets an operator diff this
     * command's dry-run output against research.md's day-by-day
     * defect-window figures.
     */
    protected function breakdownByDay(TaxBackfillRun $run): array
    {
        $rows = TaxBackfillRecord::query()
            ->join('transactions', 'transactions.id', '=', 'tax_backfill_records.transaction_pk')
            ->select(
                DB::raw('DATE(transactions.created_at) as scan_day'),
                'tax_backfill_records.outcome',
                DB::raw('COUNT(*) as outcome_count')
            )
            ->where('tax_backfill_records.run_id', $run->id)
            ->groupBy('scan_day', 'tax_backfill_records.outcome')
            ->get();

        return $this->pivotByOutcome($rows, 'scan_day');
    }

    /**
     * Shared pivot: turns {$groupKey, outcome, outcome_count} rows into a
     * list of per-group rows with scanned/reconstructed/skipped_existing/
     * quarantined/failed counts — the same shape breakdownByTenant() and
     * breakdownByDay() both need, kept in one place so they can't drift.
     *
     * @param  Collection<int, object{outcome: string, outcome_count: int}>  $rows
     * @return list<array<string, int|string>>
     */
    protected function pivotByOutcome(Collection $rows, string $groupKey): array
    {
        $outcomeToField = [
            TaxBackfillOutcome::Applied->value => 'reconstructed',
            TaxBackfillOutcome::SkippedExisting->value => 'skipped_existing',
            TaxBackfillOutcome::Quarantined->value => 'quarantined',
            TaxBackfillOutcome::Failed->value => 'failed',
        ];

        $grouped = [];

        foreach ($rows as $row) {
            $key = (string) $row->{$groupKey};

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    $groupKey => $row->{$groupKey},
                    'scanned' => 0,
                    'reconstructed' => 0,
                    'skipped_existing' => 0,
                    'quarantined' => 0,
                    'failed' => 0,
                ];
            }

            $field = $outcomeToField[$row->outcome] ?? null;
            $count = (int) $row->outcome_count;

            if ($field !== null) {
                $grouped[$key][$field] += $count;
            }

            $grouped[$key]['scanned'] += $count;
        }

        ksort($grouped);

        return array_values($grouped);
    }

    protected function render(array $result): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info(sprintf(
            'Tax backfill %s #%d — status: %s (window %s to %s)',
            $result['mode'] === TaxBackfillRun::MODE_APPLY ? 'apply run' : 'dry run',
            $result['run_id'],
            $result['status'],
            $result['window']['start'],
            $result['window']['end']
        ));

        if ($result['status'] === TaxBackfillRun::STATUS_PREFLIGHT_FAILED) {
            $this->renderPreflightFailure($result['preflight_checks']);
        } elseif ($result['preflight_checks'] !== null) {
            $this->renderPreflightFacts($result['preflight_checks']);
        }

        $this->table(
            ['Scanned', 'Reconstructed', 'Skipped (existing)', 'Quarantined', 'Failed'],
            [[
                $result['totals']['scanned'],
                $result['totals']['reconstructed'],
                $result['totals']['skipped_existing'],
                $result['totals']['quarantined'],
                $result['totals']['failed'],
            ]]
        );

        if ($result['per_tenant'] !== []) {
            $this->line('Per tenant:');
            $this->table(
                ['Tenant', 'Scanned', 'Reconstructed', 'Skipped', 'Quarantined', 'Failed'],
                collect($result['per_tenant'])->map(fn ($row) => [
                    $row['tenant_id'],
                    $row['scanned'],
                    $row['reconstructed'],
                    $row['skipped_existing'],
                    $row['quarantined'],
                    $row['failed'],
                ])->all()
            );
        }

        if ($result['per_day'] !== []) {
            $this->line('Per day:');
            $this->table(
                ['Day', 'Scanned', 'Reconstructed', 'Skipped', 'Quarantined', 'Failed'],
                collect($result['per_day'])->map(fn ($row) => [
                    $row['scan_day'],
                    $row['scanned'],
                    $row['reconstructed'],
                    $row['skipped_existing'],
                    $row['quarantined'],
                    $row['failed'],
                ])->all()
            );
        }
    }

    /**
     * Slice 9 (T097): a run that failed schema pre-flight is the one place
     * "loud failure" matters as much as Slice 5's verification oracle did —
     * an operator reading this output must immediately understand *why*
     * nothing happened, without cross-referencing anything else.
     *
     * @param  array{index_present: bool, fk_present: bool, fk_on_delete_action: string|null, transaction_pk_nullable: bool, passed: bool}  $checks
     */
    protected function renderPreflightFailure(array $checks): void
    {
        $this->newLine();
        $this->error('SCHEMA PRE-FLIGHT FAILED — no transactions were scanned, nothing was written.');

        $failedChecks = array_filter([
            $checks['index_present'] ? null : 'missing index: idx_tx_taxes_pk on transaction_taxes.transaction_pk',
            $checks['fk_present'] ? null : 'missing foreign key: fk_tx_taxes_pk on transaction_taxes.transaction_pk',
        ]);

        foreach ($failedChecks as $reason) {
            $this->error('  - '.$reason);
        }

        $this->renderPreflightFacts($checks);
        $this->newLine();
    }

    /**
     * Plain, non-alarming rendering of the observed pre-flight facts for a
     * run that has them — used both standalone (a passing apply run) and as
     * part of the louder failure rendering above.
     *
     * @param  array{index_present: bool, fk_present: bool, fk_on_delete_action: string|null, transaction_pk_nullable: bool, passed: bool}  $checks
     */
    protected function renderPreflightFacts(array $checks): void
    {
        $this->line('Schema pre-flight:');
        $this->table(
            ['Index present (idx_tx_taxes_pk)', 'FK present (fk_tx_taxes_pk)', 'FK ON DELETE action', 'transaction_pk nullable', 'Passed'],
            [[
                $checks['index_present'] ? 'yes' : 'NO',
                $checks['fk_present'] ? 'yes' : 'NO',
                $checks['fk_on_delete_action'] ?? 'n/a',
                $checks['transaction_pk_nullable'] ? 'yes' : 'no',
                $checks['passed'] ? 'yes' : 'NO',
            ]]
        );
    }
}
