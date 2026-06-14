<?php

namespace App\Console\Commands;

use App\Services\DuplicateReceiptMonitorService;
use Illuminate\Console\Command;

class DuplicateReceiptReport extends Command
{
    protected $signature = 'tsms:duplicate-receipts
        {--from= : Transaction timestamp start date/time}
        {--to= : Transaction timestamp end date/time}
        {--tenant= : Limit to one tenant ID}
        {--terminal= : Limit to one terminal ID}
        {--limit=100 : Maximum duplicate groups or legacy conflicts to show per section}
        {--json : Emit machine-readable JSON instead of tables}';

    protected $description = 'Read-only duplicate receipt monitoring/audit report by tenant, terminal, receipt, and transaction date';

    public function handle(DuplicateReceiptMonitorService $monitor): int
    {
        $report = $monitor->report([
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'tenant' => $this->option('tenant'),
            'terminal' => $this->option('terminal'),
            'limit' => $this->option('limit'),
        ]);

        if ($report['unavailable']) {
            $this->warn('transactions.receipt_no is not available.');
            return self::SUCCESS;
        }

        $duplicateGroups = $report['duplicate_groups'];
        $legacyConflicts = $report['legacy_payload_conflicts'];

        if ($this->option('json')) {
            $this->line(json_encode([
                'duplicate_groups' => $duplicateGroups,
                'legacy_payload_conflicts' => $legacyConflicts,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Duplicate receipt monitoring / audit report');

        if ($duplicateGroups === []) {
            $this->line('No duplicate populated receipt groups found.');
        } else {
            $this->warn('Duplicate populated receipt groups: ' . count($duplicateGroups));
            $this->table(
                ['Tenant', 'Terminal', 'Receipt', 'Date', 'Count', 'Transaction IDs', 'Hardware IDs'],
                array_map(fn (array $row) => [
                    $row['tenant_id'],
                    $row['terminal_id'],
                    $row['receipt_no'],
                    $row['transaction_date'],
                    $row['count'],
                    implode(', ', $row['transaction_ids']),
                    implode(', ', $row['hardware_ids']),
                ], $duplicateGroups)
            );
        }

        if ($legacyConflicts === []) {
            $this->line('No legacy payload receipt conflicts found.');
        } else {
            $this->warn('Legacy payload receipt conflicts: ' . count($legacyConflicts));
            $this->table(
                ['Legacy TX PK', 'Tenant', 'Terminal', 'Receipt', 'Date', 'Legacy TXID', 'Existing TXID', 'Existing TX PK'],
                array_map(fn (array $row) => [
                    $row['legacy_transaction_pk'],
                    $row['tenant_id'],
                    $row['terminal_id'],
                    $row['receipt_no'],
                    $row['transaction_date'],
                    $row['legacy_transaction_id'],
                    $row['existing_transaction_id'],
                    $row['existing_transaction_pk'],
                ], $legacyConflicts)
            );
        }

        return self::SUCCESS;
    }
}
