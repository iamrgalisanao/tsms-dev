<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LicenseBindingAuditCommand extends Command
{
    protected $signature = 'license:bindings:audit
        {--limit=25 : Maximum sample IDs per issue}
        {--json : Emit machine-readable JSON instead of tables}';

    protected $description = 'Read-only audit of tenant, terminal, and transaction license binding readiness';

    public function handle(): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $report = [
            'expected' => [
                'deployment_id' => config('license.deployment_id'),
                'license_id' => config('license.license_id'),
                'location_code' => config('license.location_code'),
            ],
            'schema_ready' => $this->schemaReady(),
            'issues' => [
                'tenants' => $this->tenantIssues($limit),
                'terminals' => $this->terminalIssues($limit),
                'transactions' => $this->transactionIssues($limit),
            ],
        ];

        $report['summary'] = $this->summarize($report['issues']);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->info('License binding readiness audit');
        $this->line('Expected deployment: ' . ($report['expected']['deployment_id'] ?: '-'));
        $this->line('Expected license: ' . ($report['expected']['license_id'] ?: '-'));
        $this->line('Expected location: ' . ($report['expected']['location_code'] ?: '-'));

        $this->table(['Area', 'Issue', 'Count', 'Sample IDs'], $this->tableRows($report['issues']));

        if ($report['summary']['total_issues'] > 0) {
            $this->warn('Binding gaps found. Keep enforcement in observe mode until backfill is complete.');
        } else {
            $this->info('No binding gaps found by this audit.');
        }

        return self::SUCCESS;
    }

    private function schemaReady(): array
    {
        return [
            'tenants' => $this->hasColumns('tenants', ['location_code', 'deployment_id', 'license_id']),
            'pos_terminals' => $this->hasColumns('pos_terminals', [
                'location_code',
                'deployment_id',
                'license_id',
                'activation_status',
                'activated_at',
                'revoked_at',
            ]),
            'transactions' => $this->hasColumns('transactions', ['deployment_id']),
            'terminal_activations' => Schema::hasTable('terminal_activations'),
        ];
    }

    private function tenantIssues(int $limit): array
    {
        if (!$this->hasColumns('tenants', ['location_code', 'deployment_id', 'license_id'])) {
            return [
                'schema_missing' => $this->issue(1, []),
            ];
        }

        $activeQuery = DB::table('tenants');

        if (Schema::hasColumn('tenants', 'deleted_at')) {
            $activeQuery->whereNull('deleted_at');
        }

        if (Schema::hasColumn('tenants', 'status')) {
            $activeQuery->where('status', 'Operational');
        }

        return [
            'missing_location_code' => $this->missingIssue($activeQuery, 'location_code', $limit),
            'missing_deployment_id' => $this->missingIssue($activeQuery, 'deployment_id', $limit),
            'missing_license_id' => $this->missingIssue($activeQuery, 'license_id', $limit),
            'out_of_scope_location_code' => $this->mismatchIssue($activeQuery, 'location_code', config('license.location_code'), $limit),
            'out_of_scope_deployment_id' => $this->mismatchIssue($activeQuery, 'deployment_id', config('license.deployment_id'), $limit),
            'out_of_scope_license_id' => $this->mismatchIssue($activeQuery, 'license_id', config('license.license_id'), $limit),
        ];
    }

    private function terminalIssues(int $limit): array
    {
        if (!$this->hasColumns('pos_terminals', ['location_code', 'deployment_id', 'license_id', 'activation_status'])) {
            return [
                'schema_missing' => $this->issue(1, []),
            ];
        }

        $activeQuery = DB::table('pos_terminals');
        if (Schema::hasColumn('pos_terminals', 'is_active')) {
            $activeQuery->where('is_active', true);
        }

        return [
            'missing_location_code' => $this->missingIssue($activeQuery, 'location_code', $limit),
            'missing_deployment_id' => $this->missingIssue($activeQuery, 'deployment_id', $limit),
            'missing_license_id' => $this->missingIssue($activeQuery, 'license_id', $limit),
            'missing_activation_status' => $this->missingIssue($activeQuery, 'activation_status', $limit),
            'out_of_scope_location_code' => $this->mismatchIssue($activeQuery, 'location_code', config('license.location_code'), $limit),
            'out_of_scope_deployment_id' => $this->mismatchIssue($activeQuery, 'deployment_id', config('license.deployment_id'), $limit),
            'out_of_scope_license_id' => $this->mismatchIssue($activeQuery, 'license_id', config('license.license_id'), $limit),
            'missing_tenant' => $this->terminalTenantIssue($limit),
        ];
    }

    private function transactionIssues(int $limit): array
    {
        if (!$this->hasColumns('transactions', ['deployment_id'])) {
            return [
                'schema_missing' => $this->issue(1, []),
            ];
        }

        $query = DB::table('transactions');

        return [
            'missing_deployment_id' => $this->missingIssue($query, 'deployment_id', $limit),
            'out_of_scope_deployment_id' => $this->mismatchIssue($query, 'deployment_id', config('license.deployment_id'), $limit),
        ];
    }

    private function missingIssue($baseQuery, string $column, int $limit): array
    {
        $query = clone $baseQuery;
        $query->where(function ($inner) use ($column) {
            $inner->whereNull($column)
                ->orWhere($column, '');
        });

        return $this->issue(
            (clone $query)->count(),
            (clone $query)->orderBy('id')->limit($limit)->pluck('id')->all()
        );
    }

    private function mismatchIssue($baseQuery, string $column, mixed $expectedValue, int $limit): array
    {
        if ($expectedValue === null || trim((string) $expectedValue) === '') {
            return $this->issue(0, []);
        }

        $query = clone $baseQuery;
        $query->whereNotNull($column)
            ->where($column, '!=', '')
            ->where($column, '!=', (string) $expectedValue);

        return $this->issue(
            (clone $query)->count(),
            (clone $query)->orderBy('id')->limit($limit)->pluck('id')->all()
        );
    }

    private function terminalTenantIssue(int $limit): array
    {
        if (!Schema::hasColumn('pos_terminals', 'tenant_id') || !Schema::hasTable('tenants')) {
            return $this->issue(0, []);
        }

        $query = DB::table('pos_terminals as terminals')
            ->leftJoin('tenants', 'terminals.tenant_id', '=', 'tenants.id')
            ->whereNull('tenants.id');

        if (Schema::hasColumn('pos_terminals', 'is_active')) {
            $query->where('terminals.is_active', true);
        }

        return $this->issue(
            (clone $query)->count(),
            (clone $query)->orderBy('terminals.id')->limit($limit)->pluck('terminals.id')->all()
        );
    }

    private function issue(int $count, array $sampleIds): array
    {
        return [
            'count' => $count,
            'sample_ids' => array_values($sampleIds),
        ];
    }

    private function summarize(array $issues): array
    {
        $total = 0;
        foreach ($issues as $areaIssues) {
            foreach ($areaIssues as $issue) {
                $total += (int) ($issue['count'] ?? 0);
            }
        }

        return ['total_issues' => $total];
    }

    private function tableRows(array $issues): array
    {
        $rows = [];
        foreach ($issues as $area => $areaIssues) {
            foreach ($areaIssues as $name => $issue) {
                $rows[] = [
                    $area,
                    $name,
                    $issue['count'],
                    implode(', ', $issue['sample_ids']),
                ];
            }
        }

        return $rows;
    }

    private function hasColumns(string $table, array $columns): bool
    {
        if (!Schema::hasTable($table)) {
            return false;
        }

        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
}
