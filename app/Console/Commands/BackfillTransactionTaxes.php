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
 * 002-backfill-transaction-taxes, Slice 4 (T017-T022).
 *
 * Deliberately dry-run/operator-surface only for this slice: proves command
 * ergonomics, input validation, counts, JSON output, and TaxBackfillRun
 * creation via TaxBackfillRunner::dryRun(). This command NEVER writes to
 * `transaction_taxes` — `--apply` is rejected outright below rather than
 * implemented; the real write path is a later slice.
 *
 * Ergonomics mirror App\Console\Commands\LicenseBindingBackfillCommand per
 * specs/002-backfill-transaction-taxes/contracts/cli-contract.md (research
 * R7): dry-run-by-default, a single result array driving both human and
 * --json output, and a summary table for the human path.
 */
class BackfillTransactionTaxes extends Command
{
    protected $signature = 'transactions:backfill-taxes
        {--from= : Window start (Y-m-d). Required unless --day is given.}
        {--to= : Window end (Y-m-d, inclusive). Required unless --day is given.}
        {--day= : Single day (Y-m-d). Shorthand for --from/--to over that one day.}
        {--tenant=* : Restrict to one or more tenant ids. Repeatable, e.g. --tenant=1 --tenant=2.}
        {--apply : Persist changes. NOT YET IMPLEMENTED — rejected in this build.}
        {--chunk=500 : Transactions per chunk.}
        {--limit= : Stop after N transactions scanned (piloting).}
        {--json : Emit machine-readable JSON instead of a summary table.}';

    protected $description = 'Dry-run reconstruction of missing transaction_taxes rows (Slice 4: dry-run only, --apply not yet implemented)';

    public function handle(TaxBackfillRunner $runner): int
    {
        // --apply is rejected outright in this slice, before any other
        // validation or runner execution — no code path here ever uses it.
        if ($this->option('apply')) {
            $this->error('--apply is not yet implemented — this command is dry-run only in the current build.');

            return self::FAILURE;
        }

        $validationError = $this->validateInput();

        if ($validationError !== null) {
            $this->error($validationError);

            return self::FAILURE;
        }

        [$from, $to] = $this->resolveWindow();
        $tenantIds = $this->resolveTenantIds();
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $chunk = (int) $this->option('chunk');

        $run = $runner->dryRun($from, $to, $tenantIds, $limit, $chunk);

        $result = $this->buildResult($run);

        $this->render($result);

        if ($result['status'] !== TaxBackfillRun::STATUS_COMPLETED || $result['totals']['failed'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
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

        $hasDay = $day !== null && $day !== '';
        $hasFrom = $from !== null && $from !== '';
        $hasTo = $to !== null && $to !== '';

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
            'Tax backfill dry run #%d — status: %s (window %s to %s)',
            $result['run_id'],
            $result['status'],
            $result['window']['start'],
            $result['window']['end']
        ));

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
}
