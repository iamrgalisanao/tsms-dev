<?php

namespace App\Console\Commands;

use App\Services\TenantIngestionAuditService;
use Illuminate\Console\Command;

class TenantIngestionAudit extends Command
{
    protected $signature = 'tsms:ingestion-audit
        {--from= : Start date/time for the audit window}
        {--to= : End date/time for the audit window}
        {--tenant= : Limit to one tenant ID}
        {--terminal= : Limit to one terminal ID}
        {--limit=200 : Maximum tenant rows to show}
        {--only-issues : Show only tenants with warning flags}
        {--json : Emit machine-readable JSON instead of a table}';

    protected $description = 'Read-only tenant ingestion reconciliation report for no-data and variance investigations';

    public function handle(TenantIngestionAuditService $audit): int
    {
        $report = $audit->report([
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'tenant' => $this->option('tenant'),
            'terminal' => $this->option('terminal'),
            'limit' => $this->option('limit'),
            'only_issues' => $this->option('only-issues'),
        ]);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->info("Tenant ingestion audit ({$report['window']['from']} to {$report['window']['to']})");
        $this->table(
            [
                'Tenant',
                'Name',
                'Terminals',
                'Submissions',
                'Quarantine',
                'Intake',
                'Tx',
                'Valid',
                'Pending',
                'Invalid/Failed',
                'Gross',
                'Last Tx',
                'Flags',
            ],
            array_map(fn (array $row) => [
                $row['tenant_id'],
                $row['tenant'],
                $row['active_terminals'] . '/' . $row['terminals'] . ' active, ' . $row['terminals_without_tx'] . ' no-tx',
                $row['submissions'],
                $row['quarantined'],
                $row['intake_received'],
                $row['transactions'],
                $row['valid'],
                $row['pending'],
                $row['invalid_or_failed'],
                number_format($row['gross_sales'], 2),
                $row['last_transaction_at'] ?? '-',
                implode(', ', $row['flags']),
            ], $report['rows'])
        );

        return self::SUCCESS;
    }
}
