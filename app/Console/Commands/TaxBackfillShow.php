<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TaxBackfillRecord;
use Illuminate\Console\Command;

/**
 * 002-backfill-transaction-taxes, T042 (Command 4) — see
 * specs/002-backfill-transaction-taxes/contracts/cli-contract.md.
 *
 * Read-only inspection surface for `tax_backfill_records`, mirroring the
 * `IngestionQuarantine{List,Show}` precedent (US3 auditability). Two
 * mutually exclusive modes: `--transaction=` (one transaction's full
 * correction history) and `--quarantined` (the unrecoverable rows, e.g. the
 * 216 confirmed by research.md V1a). Never mutates `tax_backfill_records`
 * or any other table — this command has no `--apply` flag because there is
 * nothing here to apply.
 */
class TaxBackfillShow extends Command
{
    protected const DEFAULT_QUARANTINED_LIMIT = 100;

    protected $signature = 'transactions:tax-backfill-show
        {--transaction= : Show correction history for one transaction (by transactions.id)}
        {--run= : Restrict to one backfill run}
        {--quarantined : List quarantined (unreconstructable) rows instead}
        {--limit= : Max quarantined rows to show (default 100; --quarantined only)}
        {--json}';

    protected $description = 'Read-only inspection of tax-backfill correction history and quarantined rows (T042)';

    public function handle(): int
    {
        $validationError = $this->validateInput();

        if ($validationError !== null) {
            $this->error($validationError);

            return self::FAILURE;
        }

        if ($this->option('quarantined')) {
            return $this->showQuarantined();
        }

        return $this->showTransactionHistory();
    }

    protected function validateInput(): ?string
    {
        $hasTransaction = $this->option('transaction') !== null && $this->option('transaction') !== '';
        $hasQuarantined = (bool) $this->option('quarantined');

        if (! $hasTransaction && ! $hasQuarantined) {
            return 'Either --transaction=<id> or --quarantined is required.';
        }

        if ($hasTransaction && $hasQuarantined) {
            return '--transaction and --quarantined are mutually exclusive; pass only one.';
        }

        if ($hasTransaction && ! $this->isPositiveInteger($this->option('transaction'))) {
            return "Invalid --transaction value '{$this->option('transaction')}': must be a positive integer.";
        }

        $run = $this->option('run');
        if ($run !== null && $run !== '' && ! $this->isPositiveInteger($run)) {
            return "Invalid --run value '{$run}': must be a positive integer.";
        }

        $limit = $this->option('limit');
        if ($limit !== null && $limit !== '' && ! $this->isPositiveInteger($limit)) {
            return "Invalid --limit value '{$limit}': must be a positive integer.";
        }

        if ($limit !== null && $limit !== '' && ! $hasQuarantined) {
            return '--limit only applies to --quarantined.';
        }

        return null;
    }

    protected function isPositiveInteger(mixed $value): bool
    {
        return ctype_digit((string) $value) && (int) $value > 0;
    }

    protected function showTransactionHistory(): int
    {
        $transactionPk = (int) $this->option('transaction');

        $runFilter = $this->runFilter();

        $records = TaxBackfillRecord::query()
            ->where('transaction_pk', $transactionPk)
            ->when($runFilter !== null, fn ($query) => $query->where('run_id', $runFilter))
            ->orderBy('created_at')
            ->get();

        $rows = $records->map(fn (TaxBackfillRecord $record) => [
            'record_id' => $record->id,
            'run_id' => $record->run_id,
            'outcome' => $record->outcome,
            'reason_code' => $record->reason_code,
            'had_linked_rows_before' => $record->had_linked_rows_before,
            'reconstructed_tax_row_count' => is_array($record->reconstructed_tax_rows) ? count($record->reconstructed_tax_rows) : 0,
            'archive_reference' => $record->archive_reference,
            'created_at' => optional($record->created_at)->toDateTimeString(),
        ])->all();

        $result = [
            'mode' => 'transaction',
            'transaction_pk' => $transactionPk,
            'run_filter' => $runFilter,
            'record_count' => count($rows),
            'records' => $rows,
        ];

        $this->render($result, "No tax-backfill records found for transaction #{$transactionPk}.");

        return self::SUCCESS;
    }

    protected function showQuarantined(): int
    {
        $runFilter = $this->runFilter();
        $limit = $this->isPositiveInteger($this->option('limit'))
            ? (int) $this->option('limit')
            : self::DEFAULT_QUARANTINED_LIMIT;

        $query = TaxBackfillRecord::query()
            ->where('outcome', 'quarantined')
            ->when($runFilter !== null, fn ($q) => $q->where('run_id', $runFilter))
            ->orderBy('created_at');

        $totalCount = $query->count();
        $records = $query->limit($limit)->get();

        $rows = $records->map(fn (TaxBackfillRecord $record) => [
            'record_id' => $record->id,
            'run_id' => $record->run_id,
            'transaction_pk' => $record->transaction_pk,
            'tenant_id' => $record->tenant_id,
            'reason_code' => $record->reason_code,
            'created_at' => optional($record->created_at)->toDateTimeString(),
        ])->all();

        $result = [
            'mode' => 'quarantined',
            'run_filter' => $runFilter,
            'total_count' => $totalCount,
            'shown_count' => count($rows),
            'truncated' => $totalCount > count($rows),
            'limit' => $limit,
            'records' => $rows,
        ];

        $this->render($result, 'No quarantined tax-backfill records found.');

        return self::SUCCESS;
    }

    protected function runFilter(): ?int
    {
        $run = $this->option('run');

        return ($run !== null && $run !== '') ? (int) $run : null;
    }

    protected function render(array $result, string $emptyMessage): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        if (empty($result['records'])) {
            $this->info($emptyMessage);

            return;
        }

        if ($result['mode'] === 'transaction') {
            $this->info("Tax-backfill history for transaction #{$result['transaction_pk']} — {$result['record_count']} record(s).");
            $this->table(
                ['Record ID', 'Run ID', 'Outcome', 'Reason Code', 'Had Linked Rows Before', 'Reconstructed Rows', 'Created At'],
                array_map(fn (array $r) => [
                    $r['record_id'],
                    $r['run_id'],
                    $r['outcome'],
                    $r['reason_code'] ?? '-',
                    $r['had_linked_rows_before'] ? 'yes' : 'no',
                    $r['reconstructed_tax_row_count'],
                    $r['created_at'],
                ], $result['records'])
            );

            return;
        }

        $this->info("Quarantined tax-backfill records — showing {$result['shown_count']} of {$result['total_count']}.");
        if ($result['truncated']) {
            $this->line('Output truncated at '.$result['limit'].'; use --limit= or narrow with --run= to see more.');
        }
        $this->table(
            ['Record ID', 'Run ID', 'Transaction PK', 'Tenant', 'Reason Code', 'Created At'],
            array_map(fn (array $r) => [
                $r['record_id'],
                $r['run_id'],
                $r['transaction_pk'],
                $r['tenant_id'],
                $r['reason_code'] ?? '-',
                $r['created_at'],
            ], $result['records'])
        );
    }
}
