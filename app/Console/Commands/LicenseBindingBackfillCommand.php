<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LicenseBindingBackfillCommand extends Command
{
    protected $signature = 'license:bindings:backfill
        {--apply : Persist changes. Without this flag the command is dry-run only}
        {--include-transactions : Include transaction deployment_id backfill}
        {--chunk=500 : Number of rows to update per chunk}
        {--limit=25 : Maximum sample IDs per section}
        {--json : Emit machine-readable JSON instead of tables}';

    protected $description = 'Dry-run or apply license binding backfill for tenants, terminals, and optionally transactions';

    public function handle(): int
    {
        $scope = $this->scope();
        $schemaErrors = $this->schemaErrors();

        if ($scope['errors'] !== [] || $schemaErrors !== []) {
            $report = [
                'applied' => false,
                'ready' => false,
                'scope' => $scope,
                'schema_errors' => $schemaErrors,
            ];

            $this->render($report);
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $includeTransactions = (bool) $this->option('include-transactions');
        $chunk = max(1, min(5000, (int) $this->option('chunk')));
        $limit = max(1, min(500, (int) $this->option('limit')));

        $report = [
            'applied' => $apply,
            'ready' => true,
            'include_transactions' => $includeTransactions,
            'scope' => $scope,
            'sections' => [
                'tenants' => $this->backfillTenants($scope, $apply, $chunk, $limit),
                'terminals' => $this->backfillTerminals($scope, $apply, $chunk, $limit),
                'terminal_activations' => $this->backfillTerminalActivations($scope, $apply, $chunk, $limit),
                'transactions' => $includeTransactions
                    ? $this->backfillTransactions($scope, $apply, $chunk, $limit)
                    : ['skipped' => true, 'reason' => 'Pass --include-transactions to include transaction deployment_id backfill.'],
            ],
        ];

        $this->render($report);

        return self::SUCCESS;
    }

    private function backfillTenants(array $scope, bool $apply, int $chunk, int $limit): array
    {
        $base = DB::table('tenants');
        if (Schema::hasColumn('tenants', 'deleted_at')) {
            $base->whereNull('deleted_at');
        }

        if (Schema::hasColumn('tenants', 'status')) {
            $base->where('status', 'Operational');
        }

        $query = $this->missingAny($base, ['location_code', 'deployment_id', 'license_id']);
        $ids = (clone $query)->orderBy('id')->limit($limit)->pluck('id')->all();
        $count = (clone $query)->count();

        if ($apply) {
            (clone $query)->orderBy('id')->chunkById($chunk, function ($rows) use ($scope) {
                DB::table('tenants')
                    ->whereIn('id', $rows->pluck('id')->all())
                    ->update([
                        'location_code' => $scope['location_code'],
                        'deployment_id' => $scope['deployment_id'],
                        'license_id' => $scope['license_id'],
                        'updated_at' => now(),
                    ]);
            });
        }

        return $this->section($count, $ids);
    }

    private function backfillTerminals(array $scope, bool $apply, int $chunk, int $limit): array
    {
        $base = DB::table('pos_terminals');
        if (Schema::hasColumn('pos_terminals', 'is_active')) {
            $base->where('is_active', true);
        }

        $query = $this->missingAny($base, ['location_code', 'deployment_id', 'license_id', 'activation_status']);
        $ids = (clone $query)->orderBy('id')->limit($limit)->pluck('id')->all();
        $count = (clone $query)->count();

        if ($apply) {
            (clone $query)->orderBy('id')->chunkById($chunk, function ($rows) use ($scope) {
                DB::table('pos_terminals')
                    ->whereIn('id', $rows->pluck('id')->all())
                    ->update([
                        'location_code' => $scope['location_code'],
                        'deployment_id' => $scope['deployment_id'],
                        'license_id' => $scope['license_id'],
                        'activation_status' => 'active',
                        'activated_at' => now(),
                        'updated_at' => now(),
                    ]);
            });
        }

        return $this->section($count, $ids);
    }

    private function backfillTerminalActivations(array $scope, bool $apply, int $chunk, int $limit): array
    {
        $base = DB::table('pos_terminals as terminals')
            ->leftJoin('terminal_activations as activations', function ($join) use ($scope) {
                $join->on('activations.terminal_id', '=', 'terminals.id')
                    ->where('activations.deployment_id', '=', $scope['deployment_id'])
                    ->where('activations.license_id', '=', $scope['license_id']);
            })
            ->whereNull('activations.id');

        if (Schema::hasColumn('pos_terminals', 'is_active')) {
            $base->where('terminals.is_active', true);
        }

        $ids = (clone $base)->orderBy('terminals.id')->limit($limit)->pluck('terminals.id')->all();
        $count = (clone $base)->count();

        if ($apply) {
            (clone $base)
                ->select('terminals.id', 'terminals.tenant_id')
                ->orderBy('terminals.id')
                ->chunkById($chunk, function ($rows) use ($scope) {
                    $now = now();
                    $records = $rows->map(fn ($row) => [
                        'terminal_id' => $row->id,
                        'tenant_id' => $row->tenant_id,
                        'license_id' => $scope['license_id'],
                        'deployment_id' => $scope['deployment_id'],
                        'location_code' => $scope['location_code'],
                        'activation_status' => 'active',
                        'activated_at' => $now,
                        'metadata' => json_encode(['source' => 'license:bindings:backfill']),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all();

                    if ($records !== []) {
                        DB::table('terminal_activations')->insert($records);
                    }
                }, 'terminals.id', 'id');
        }

        return $this->section($count, $ids);
    }

    private function backfillTransactions(array $scope, bool $apply, int $chunk, int $limit): array
    {
        $query = DB::table('transactions')
            ->where(function ($inner) {
                $inner->whereNull('deployment_id')
                    ->orWhere('deployment_id', '');
            });

        $ids = (clone $query)->orderBy('id')->limit($limit)->pluck('id')->all();
        $count = (clone $query)->count();

        if ($apply) {
            (clone $query)->orderBy('id')->chunkById($chunk, function ($rows) use ($scope) {
                DB::table('transactions')
                    ->whereIn('id', $rows->pluck('id')->all())
                    ->update([
                        'deployment_id' => $scope['deployment_id'],
                        'updated_at' => now(),
                    ]);
            });
        }

        return $this->section($count, $ids);
    }

    private function missingAny($query, array $columns)
    {
        return $query->where(function ($outer) use ($columns) {
            foreach ($columns as $column) {
                $outer->orWhereNull($column)->orWhere($column, '');
            }
        });
    }

    private function section(int $candidateCount, array $sampleIds): array
    {
        return [
            'candidate_count' => $candidateCount,
            'sample_ids' => array_values($sampleIds),
        ];
    }

    private function scope(): array
    {
        $scope = [
            'deployment_id' => config('license.deployment_id'),
            'license_id' => config('license.license_id'),
            'location_code' => config('license.location_code'),
            'errors' => [],
        ];

        foreach (['deployment_id', 'license_id', 'location_code'] as $key) {
            if ($scope[$key] === null || trim((string) $scope[$key]) === '') {
                $scope['errors'][$key][] = 'This license scope value must be configured before backfill.';
            }
        }

        return $scope;
    }

    private function schemaErrors(): array
    {
        $required = [
            'tenants' => ['location_code', 'deployment_id', 'license_id'],
            'pos_terminals' => ['location_code', 'deployment_id', 'license_id', 'activation_status', 'activated_at'],
            'transactions' => ['deployment_id'],
            'terminal_activations' => [],
        ];

        $errors = [];
        foreach ($required as $table => $columns) {
            if (!Schema::hasTable($table)) {
                $errors[$table][] = 'Table is missing.';
                continue;
            }

            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    $errors[$table][] = "Column {$column} is missing.";
                }
            }
        }

        return $errors;
    }

    private function render(array $report): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }

        $this->info($report['applied'] ? 'License binding backfill applied' : 'License binding backfill dry run');

        if (!$report['ready']) {
            foreach ($report['scope']['errors'] ?? [] as $key => $messages) {
                foreach ($messages as $message) {
                    $this->error("Scope {$key}: {$message}");
                }
            }

            foreach ($report['schema_errors'] ?? [] as $table => $messages) {
                foreach ($messages as $message) {
                    $this->error("Schema {$table}: {$message}");
                }
            }

            return;
        }

        $rows = [];
        foreach ($report['sections'] as $section => $data) {
            $rows[] = [
                $section,
                $data['skipped'] ?? false ? 'skipped' : ($data['candidate_count'] ?? 0),
                $data['reason'] ?? implode(', ', $data['sample_ids'] ?? []),
            ];
        }

        $this->table(['Section', 'Candidates', 'Sample IDs / Reason'], $rows);
    }
}
