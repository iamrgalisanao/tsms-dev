<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PreBackfillSnapshotRun;
use App\Models\PreRunIntegrityCapture;
use App\Models\TaxBackfillMaterialityRecord;
use App\Models\TaxBackfillMaterialityRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 002-backfill-transaction-taxes, Slice 20 (T076) — see
 * specs/002-backfill-transaction-taxes/slice-20-readiness-verdict-brief.md.
 *
 * The feature's final evidence-gate slice: a pure reader over evidence
 * already persisted by other commands (Slice 16's pre/post-run integrity
 * captures, Slice 19's materiality runs, Slice 18's connection-identity
 * columns on report_refresh_states) plus one manual attestation
 * (--backup-drill-confirmed, for T054 — a documentation fact with no
 * database representation). Produces one pass/fail/warn verdict for the
 * rehearsal/live-run record.
 *
 * This command has NO --apply flag and writes nothing, ever — every
 * invocation recomputes the verdict fresh from whatever evidence already
 * exists. It does not capture, snapshot, refresh, backfill, archive, or
 * delete anything. Running `transactions:capture-integrity-evidence
 * --phase=post_run --apply` is a required, separate, prior operator step —
 * this command never invokes it (see PreRunIntegrityCapture's docblock,
 * corrected in this same slice after an earlier assumption that T076 would
 * capture its own post-run evidence).
 *
 * Materiality (Slice 19) is informational, never a FAIL condition: a tenant
 * crossing the FR-009a threshold is the expected, legitimate outcome of a
 * real correction, not evidence something went wrong.
 */
class TaxBackfillReadinessVerdict extends Command
{
    protected $signature = 'transactions:tax-backfill-readiness-verdict
        {--from= : Window start (Y-m-d). Required. Metadata for the materiality/connection-identity checks — the integrity captures themselves are whole-table.}
        {--to= : Window end, exclusive (Y-m-d). Required.}
        {--tenant= : Optional tenant id, narrows the materiality/connection-identity checks to one tenant.}
        {--snapshot-run= : pre_backfill_snapshot_runs.id the materiality evidence is keyed to. Omitting it surfaces materiality as a WARN, not a FAIL.}
        {--materiality-run= : tax_backfill_materiality_runs.id to read. Defaults to the most recently completed one for --snapshot-run.}
        {--pre-run-capture-id= : pre_run_integrity_captures.id (phase=pre_run). Defaults to the most recent pre_run capture.}
        {--post-run-capture-id= : pre_run_integrity_captures.id (phase=post_run). Defaults to the most recent post_run capture.}
        {--backup-drill-confirmed : Manual attestation that rollback.md\'s T054 drill evidence has been reviewed and is still valid for this run.}
        {--json}';

    protected $description = 'Read-only readiness verdict for a rehearsal/live-run record — compares already-persisted Slice 16/18/19 evidence, never captures or mutates anything';

    public function handle(): int
    {
        $validationError = $this->validateInput();

        if ($validationError !== null) {
            $this->error($validationError);

            return self::FAILURE;
        }

        [$windowStart, $windowEnd] = $this->resolveWindow();
        $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;

        $checks = [
            'pre_run_capture' => $preRunCheck = $this->checkPreRunCapture(),
            'post_run_capture' => $postRunCheck = $this->checkPostRunCapture(),
        ];
        $checks['structural_check'] = $this->checkStructural($preRunCheck, $postRunCheck);
        $checks['materiality'] = $this->checkMateriality($tenantId);
        $checks['connection_identity'] = $this->checkConnectionIdentity($windowStart, $windowEnd, $tenantId);
        $checks['backup_drill_attestation'] = $this->checkBackupDrillAttestation();

        $overallStatus = $this->overallStatus($checks);

        $result = [
            'mode' => 'read',
            'window' => ['start' => $windowStart->toDateString(), 'end' => $windowEnd->toDateString()],
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

        if ($windowEnd->lte($windowStart)) {
            return "--from ({$from}) and --to ({$to}) resolve to an empty window — --to is exclusive and must be after --from.";
        }

        foreach (['snapshot-run', 'materiality-run', 'pre-run-capture-id', 'post-run-capture-id', 'tenant'] as $option) {
            $value = $this->option($option);
            if ($value !== null && $value !== '' && ! ctype_digit((string) $value)) {
                return "Invalid --{$option} value '{$value}': must be a positive integer.";
            }
        }

        return null;
    }

    protected function isValidDate(string $value): bool
    {
        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
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
     * @return array{status: string, reason: string, capture: ?PreRunIntegrityCapture}
     */
    protected function checkPreRunCapture(): array
    {
        return $this->resolveCapture('pre-run-capture-id', PreRunIntegrityCapture::PHASE_PRE_RUN, 'pre-run');
    }

    /**
     * @return array{status: string, reason: string, capture: ?PreRunIntegrityCapture}
     */
    protected function checkPostRunCapture(): array
    {
        return $this->resolveCapture('post-run-capture-id', PreRunIntegrityCapture::PHASE_POST_RUN, 'post-run');
    }

    protected function resolveCapture(string $option, string $phase, string $label): array
    {
        $explicit = $this->option($option);
        $hasExplicit = $explicit !== null && $explicit !== '';

        // The explicit-ID path must filter on phase too, not just find() by
        // id -- otherwise an operator could pass a pre_run capture's id to
        // --post-run-capture-id (or vice versa) and get a silently
        // mislabeled, wrong-phase capture accepted as valid evidence.
        $capture = $hasExplicit
            ? PreRunIntegrityCapture::query()->where('id', (int) $explicit)->where('phase', $phase)->first()
            : PreRunIntegrityCapture::query()->where('phase', $phase)->orderByDesc('id')->first();

        if ($capture === null) {
            return [
                'status' => 'fail',
                'reason' => $hasExplicit
                    ? "No {$label} capture found with id {$explicit} and phase '{$phase}' (either the id doesn't exist, or it exists under a different phase)."
                    : "No {$label} capture exists at all. Run transactions:capture-integrity-evidence --phase={$phase} --apply first.",
                'capture' => null,
            ];
        }

        return [
            'status' => 'pass',
            'reason' => "Using capture #{$capture->id}, captured at {$capture->captured_at?->toDateTimeString()}.",
            'capture' => $capture,
        ];
    }

    protected function checkStructural(array $preRunCheck, array $postRunCheck): array
    {
        /** @var ?PreRunIntegrityCapture $preRun */
        $preRun = $preRunCheck['capture'];
        /** @var ?PreRunIntegrityCapture $postRun */
        $postRun = $postRunCheck['capture'];

        if ($postRun === null) {
            return ['status' => 'fail', 'reason' => 'No post-run capture available to assess.', 'details' => null];
        }

        if ($postRun->transaction_taxes_null_count === null) {
            return [
                'status' => 'fail',
                'reason' => "Post-run capture #{$postRun->id} predates the transaction_taxes_null_count field — re-capture with the current command version.",
                'details' => null,
            ];
        }

        if ($postRun->transaction_taxes_null_count !== 0) {
            return [
                'status' => 'fail',
                'reason' => "Post-run transaction_pk IS NULL count is {$postRun->transaction_taxes_null_count}, not zero.",
                'details' => ['transaction_taxes_null_count' => $postRun->transaction_taxes_null_count],
            ];
        }

        $preDuplicateRows = $preRun?->duplicate_check_summary['total_duplicate_rows'] ?? null;
        $postDuplicateRows = $postRun->duplicate_check_summary['total_duplicate_rows'] ?? 0;

        // Demoted to a secondary signal (Architect F8): only a FAIL if the
        // backfill run appears to have made duplicates WORSE, never merely
        // for being non-zero — payloads may legitimately repeat a tax_type,
        // and pre-existing duplicates aren't this feature's doing.
        if ($preDuplicateRows !== null && $postDuplicateRows > $preDuplicateRows) {
            return [
                'status' => 'fail',
                'reason' => "Post-run duplicate rows ({$postDuplicateRows}) exceed the pre-run baseline ({$preDuplicateRows}) — the run appears to have introduced new duplicates.",
                'details' => ['pre_run_duplicate_rows' => $preDuplicateRows, 'post_run_duplicate_rows' => $postDuplicateRows],
            ];
        }

        return [
            'status' => 'pass',
            'reason' => $preDuplicateRows !== null
                ? 'Post-run transaction_pk IS NULL count is zero, and duplicate rows did not increase versus the pre-run baseline.'
                : 'Post-run transaction_pk IS NULL count is zero. No pre-run baseline was available to compare duplicate rows against (see the pre_run_capture check).',
            'details' => [
                'transaction_taxes_null_count' => 0,
                'pre_run_duplicate_rows' => $preDuplicateRows,
                'post_run_duplicate_rows' => $postDuplicateRows,
            ],
        ];
    }

    protected function checkMateriality(?int $tenantId): array
    {
        $snapshotRunOption = $this->option('snapshot-run');

        if ($snapshotRunOption === null || $snapshotRunOption === '') {
            return ['status' => 'warn', 'reason' => '--snapshot-run not given; materiality evidence not checked.', 'summary' => null];
        }

        $snapshotRunId = (int) $snapshotRunOption;

        if (PreBackfillSnapshotRun::find($snapshotRunId) === null) {
            return ['status' => 'warn', 'reason' => "Snapshot run #{$snapshotRunId} was not found.", 'summary' => null];
        }

        $materialityRunOption = $this->option('materiality-run');

        if ($materialityRunOption !== null && $materialityRunOption !== '') {
            $materialityRun = TaxBackfillMaterialityRun::query()
                ->where('id', (int) $materialityRunOption)
                ->where('snapshot_run_id', $snapshotRunId)
                ->where('report_contract_version', TaxBackfillMaterialityRun::REPORT_CONTRACT_VERSION_CMSR_V2)
                ->first();

            if ($materialityRun === null) {
                return [
                    'status' => 'warn',
                    'reason' => "Materiality run #{$materialityRunOption} was not found, does not belong to snapshot run #{$snapshotRunId}, or is not on the current CMSR report contract.",
                    'summary' => null,
                ];
            }
        } else {
            $materialityRun = TaxBackfillMaterialityRun::query()
                ->where('snapshot_run_id', $snapshotRunId)
                ->where('report_contract_version', TaxBackfillMaterialityRun::REPORT_CONTRACT_VERSION_CMSR_V2)
                ->where('status', TaxBackfillMaterialityRun::STATUS_COMPLETED)
                ->orderByDesc('id')
                ->first();
        }

        if ($materialityRun === null) {
            return ['status' => 'warn', 'reason' => "No completed materiality run found for snapshot run #{$snapshotRunId}.", 'summary' => null];
        }

        $records = TaxBackfillMaterialityRecord::query()
            ->where('run_id', $materialityRun->id)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->get();
        $compared = $records->where('comparison_status', TaxBackfillMaterialityRecord::COMPARISON_STATUS_COMPARED);
        $mismatched = $records->where('comparison_status', TaxBackfillMaterialityRecord::COMPARISON_STATUS_SOURCE_MISMATCH);
        $contractMismatched = $records->where('comparison_status', TaxBackfillMaterialityRecord::COMPARISON_STATUS_CONTRACT_MISMATCH);

        if ($tenantId !== null && $records->isEmpty()) {
            return [
                'status' => 'warn',
                'reason' => "Materiality run #{$materialityRun->id} has no records for tenant #{$tenantId}.",
                'summary' => null,
            ];
        }

        $summary = [
            'materiality_run_id' => $materialityRun->id,
            'population' => $records->count(),
            'compared' => $compared->count(),
            'source_mismatch' => $mismatched->count(),
            'contract_mismatch' => $contractMismatched->count(),
            'total_other_tax_delta' => round((float) $compared->sum('other_tax_delta_amount'), 2),
        ];

        if ($mismatched->count() > 0 || $contractMismatched->count() > 0) {
            return [
                'status' => 'warn',
                'reason' => sprintf(
                    '%d of %d pairs could not be compared (%d source mismatch, %d contract mismatch).',
                    $mismatched->count() + $contractMismatched->count(),
                    $records->count(),
                    $mismatched->count(),
                    $contractMismatched->count()
                ),
                'summary' => $summary,
            ];
        }

        return ['status' => 'pass', 'reason' => 'Materiality evidence available and fully compared.', 'summary' => $summary];
    }

    protected function checkConnectionIdentity(Carbon $windowStart, Carbon $windowEnd, ?int $tenantId): array
    {
        $query = DB::table('report_refresh_states')
            ->where('report_type', 'daily_transaction_summaries')
            ->whereBetween('business_date', [$windowStart->toDateString(), $windowEnd->copy()->subDay()->toDateString()])
            ->whereNotNull('server_id')
            ->whereNotNull('database_name');

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $count = $query->count();

        if ($count === 0) {
            return [
                'status' => 'fail',
                'reason' => 'No report_refresh_states rows with recorded connection identity exist for this window — the connection-identity gate (T077) never ran or never completed here.',
                'rows_checked' => 0,
            ];
        }

        return [
            'status' => 'pass',
            'reason' => "{$count} report_refresh_states row(s) confirm the aggregating connection's identity was recorded for this window.",
            'rows_checked' => $count,
        ];
    }

    protected function checkBackupDrillAttestation(): array
    {
        if (! $this->option('backup-drill-confirmed')) {
            return [
                'status' => 'warn',
                'reason' => "--backup-drill-confirmed not passed. Review rollback.md's T054 drill evidence before authorizing this run, then re-run with the flag.",
            ];
        }

        return ['status' => 'pass', 'reason' => 'Operator attested rollback.md\'s backup/restore drill evidence was reviewed and is still valid.'];
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
            $this->line(json_encode($this->jsonSafe($result), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info(sprintf(
            'Readiness verdict: %s — window %s to %s%s',
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

    /**
     * Strips non-serializable Eloquent model instances (kept in-memory for
     * checkStructural()'s comparison logic) before JSON output. Generic
     * over every check block's keys rather than a hardcoded list of which
     * blocks currently happen to hold a model — a future check block that
     * stashes a model under a new key is covered automatically.
     */
    protected function jsonSafe(array $result): array
    {
        foreach ($result['checks'] as $name => $check) {
            foreach ($check as $key => $value) {
                if ($value instanceof \Illuminate\Database\Eloquent\Model) {
                    unset($result['checks'][$name][$key]);
                }
            }
        }

        return $result;
    }
}
