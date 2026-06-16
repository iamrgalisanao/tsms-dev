<?php

namespace App\Services;

use App\Models\PosTerminal;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantIngestionAuditService
{
    public function report(array $filters = []): array
    {
        $from = $this->normalizeBoundary($filters['from'] ?? null, 'start');
        $to = $this->normalizeBoundary($filters['to'] ?? null, 'end');
        $tenantId = isset($filters['tenant']) && $filters['tenant'] !== '' ? (int) $filters['tenant'] : null;
        $terminalId = isset($filters['terminal']) && $filters['terminal'] !== '' ? (int) $filters['terminal'] : null;
        $limit = max(1, (int) ($filters['limit'] ?? 200));
        $onlyIssues = (bool) ($filters['only_issues'] ?? false);

        $tenantRows = $this->tenantRows($tenantId, $terminalId, $limit);
        $tenantIds = $tenantRows->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($tenantIds === []) {
            return [
                'window' => compact('from', 'to'),
                'filters' => [
                    'tenant_id' => $tenantId,
                    'terminal_id' => $terminalId,
                ],
                'rows' => [],
            ];
        }

        $transactions = $this->transactionMetrics($from, $to, $tenantIds, $terminalId);
        $submissions = $this->submissionMetrics($from, $to, $tenantIds, $terminalId);
        $quarantine = $this->quarantineMetrics($from, $to, $tenantIds, $terminalId);
        $intake = $this->intakeMetrics($from, $to, $tenantIds, $terminalId);
        $terminals = $this->terminalMetrics($from, $to, $tenantIds, $terminalId);

        $rows = $tenantRows
            ->map(function (Tenant $tenant) use ($transactions, $submissions, $quarantine, $intake, $terminals) {
                $tenantId = (int) $tenant->id;
                $tx = $transactions[$tenantId] ?? [];
                $sub = $submissions[$tenantId] ?? [];
                $q = $quarantine[$tenantId] ?? [];
                $in = $intake[$tenantId] ?? [];
                $term = $terminals[$tenantId] ?? [];

                return [
                    'tenant_id' => $tenantId,
                    'tenant' => $tenant->trade_name ?? ('Tenant #' . $tenantId),
                    'status' => $tenant->status,
                    'terminals' => (int) ($term['total_terminals'] ?? 0),
                    'active_terminals' => (int) ($term['active_terminals'] ?? 0),
                    'terminals_without_tx' => (int) ($term['terminals_without_tx'] ?? 0),
                    'submissions' => (int) ($sub['count'] ?? 0),
                    'quarantined' => (int) ($q['count'] ?? 0),
                    'intake_received' => (int) ($in['count'] ?? 0),
                    'intake_rejected' => (int) ($in['rejected_count'] ?? 0),
                    'intake_failed' => (int) ($in['failed_count'] ?? 0),
                    'transactions' => (int) ($tx['count'] ?? 0),
                    'valid' => (int) ($tx['valid_count'] ?? 0),
                    'pending' => (int) ($tx['pending_count'] ?? 0),
                    'invalid_or_failed' => (int) ($tx['invalid_or_failed_count'] ?? 0),
                    'job_failed' => (int) ($tx['job_failed_count'] ?? 0),
                    'voided' => (int) ($tx['voided_count'] ?? 0),
                    'refunded' => (int) ($tx['refunded_count'] ?? 0),
                    'gross_sales' => round((float) ($tx['gross_sales'] ?? 0), 2),
                    'net_sales' => round((float) ($tx['net_sales'] ?? 0), 2),
                    'tenant_terminal_drift' => (int) ($tx['tenant_terminal_drift'] ?? 0),
                    'last_transaction_at' => $tx['last_transaction_at'] ?? null,
                    'last_terminal_seen_at' => $term['last_seen_at'] ?? null,
                    'flags' => $this->flags($tx, $sub, $q, $in, $term),
                ];
            })
            ->when($onlyIssues, fn ($rows) => $rows->filter(fn (array $row) => $row['flags'] !== []))
            ->values()
            ->all();

        return [
            'window' => compact('from', 'to'),
            'filters' => [
                'tenant_id' => $tenantId,
                'terminal_id' => $terminalId,
            ],
            'rows' => $rows,
        ];
    }

    private function tenantRows(?int $tenantId, ?int $terminalId, int $limit)
    {
        $query = Tenant::query()->orderBy('id')->limit($limit);

        if ($tenantId !== null) {
            $query->whereKey($tenantId);
        }

        if ($terminalId !== null) {
            $query->whereIn('id', PosTerminal::query()
                ->select('tenant_id')
                ->whereKey($terminalId));
        }

        return $query->get(['id', 'trade_name', 'status']);
    }

    private function normalizeBoundary(?string $value, string $boundary): string
    {
        if ($value === null || trim($value) === '') {
            return $boundary === 'start'
                ? now()->startOfDay()->toDateTimeString()
                : now()->endOfDay()->toDateTimeString();
        }

        $parsed = Carbon::parse($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $boundary === 'start'
                ? $parsed->startOfDay()->toDateTimeString()
                : $parsed->endOfDay()->toDateTimeString();
        }

        return $parsed->toDateTimeString();
    }

    private function transactionMetrics(string $from, string $to, array $tenantIds, ?int $terminalId): array
    {
        if (! Schema::hasTable('transactions')) {
            return [];
        }

        $query = DB::table('transactions')
            ->leftJoin('pos_terminals', 'pos_terminals.id', '=', 'transactions.terminal_id')
            ->whereIn('transactions.tenant_id', $tenantIds)
            ->whereBetween('transactions.transaction_timestamp', [$from, $to])
            ->when($terminalId !== null, fn (Builder $query) => $query->where('transactions.terminal_id', $terminalId))
            ->groupBy('transactions.tenant_id');

        $selects = [
            'transactions.tenant_id',
            DB::raw('COUNT(*) as count'),
            DB::raw('COALESCE(SUM(transactions.gross_sales), 0) as gross_sales'),
            DB::raw('COALESCE(SUM(transactions.net_sales), 0) as net_sales'),
            DB::raw("SUM(CASE WHEN transactions.validation_status = 'VALID' THEN 1 ELSE 0 END) as valid_count"),
            DB::raw("SUM(CASE WHEN transactions.validation_status = 'PENDING' THEN 1 ELSE 0 END) as pending_count"),
            DB::raw("SUM(CASE WHEN transactions.validation_status IN ('INVALID', 'FAILED') THEN 1 ELSE 0 END) as invalid_or_failed_count"),
            DB::raw('SUM(CASE WHEN pos_terminals.tenant_id IS NOT NULL AND transactions.tenant_id <> pos_terminals.tenant_id THEN 1 ELSE 0 END) as tenant_terminal_drift'),
            DB::raw('MAX(transactions.transaction_timestamp) as last_transaction_at'),
        ];

        $selects[] = Schema::hasColumn('transactions', 'job_status')
            ? DB::raw("SUM(CASE WHEN transactions.job_status IN ('FAILED', 'PERMANENTLY_FAILED') THEN 1 ELSE 0 END) as job_failed_count")
            : DB::raw('0 as job_failed_count');

        $selects[] = Schema::hasColumn('transactions', 'voided_at')
            ? DB::raw('SUM(CASE WHEN transactions.voided_at IS NOT NULL THEN 1 ELSE 0 END) as voided_count')
            : DB::raw('0 as voided_count');

        $selects[] = Schema::hasColumn('transactions', 'refund_status')
            ? DB::raw("SUM(CASE WHEN transactions.refund_status = 'REFUNDED' THEN 1 ELSE 0 END) as refunded_count")
            : DB::raw('0 as refunded_count');

        return $query->select($selects)
            ->get()
            ->keyBy(fn ($row) => (int) $row->tenant_id)
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function submissionMetrics(string $from, string $to, array $tenantIds, ?int $terminalId): array
    {
        if (! Schema::hasTable('transaction_submissions')) {
            return [];
        }

        return DB::table('transaction_submissions')
            ->whereIn('tenant_id', $tenantIds)
            ->where(function (Builder $query) use ($from, $to) {
                $query->whereBetween('submission_timestamp', [$from, $to])
                    ->orWhere(function (Builder $query) use ($from, $to) {
                        $query->whereNull('submission_timestamp')
                            ->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->when($terminalId !== null, fn (Builder $query) => $query->where('terminal_id', $terminalId))
            ->groupBy('tenant_id')
            ->select(['tenant_id', DB::raw('COUNT(*) as count'), DB::raw('MAX(created_at) as last_submission_at')])
            ->get()
            ->keyBy(fn ($row) => (int) $row->tenant_id)
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function quarantineMetrics(string $from, string $to, array $tenantIds, ?int $terminalId): array
    {
        if (! Schema::hasTable('ingestion_quarantine')) {
            return [];
        }

        return DB::table('ingestion_quarantine')
            ->whereIn('tenant_id', $tenantIds)
            ->whereBetween('created_at', [$from, $to])
            ->when($terminalId !== null, fn (Builder $query) => $query->where('terminal_id', $terminalId))
            ->groupBy('tenant_id')
            ->select(['tenant_id', DB::raw('COUNT(*) as count'), DB::raw('MAX(created_at) as last_quarantine_at')])
            ->get()
            ->keyBy(fn ($row) => (int) $row->tenant_id)
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function intakeMetrics(string $from, string $to, array $tenantIds, ?int $terminalId): array
    {
        if (! Schema::hasTable('transaction_intake')) {
            return [];
        }

        return DB::table('transaction_intake')
            ->whereIn('tenant_id', $tenantIds)
            ->where(function (Builder $query) use ($from, $to) {
                $query->whereBetween('received_at', [$from, $to])
                    ->orWhere(function (Builder $query) use ($from, $to) {
                        $query->whereNull('received_at')
                            ->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->when($terminalId !== null, fn (Builder $query) => $query->where('terminal_id', $terminalId))
            ->groupBy('tenant_id')
            ->select([
                'tenant_id',
                DB::raw('COUNT(*) as count'),
                DB::raw("SUM(CASE WHEN intake_status = 'REJECTED' THEN 1 ELSE 0 END) as rejected_count"),
                DB::raw("SUM(CASE WHEN processing_status IN ('FAILED_RETRYABLE', 'FAILED_PERMANENT', 'DEAD_LETTERED') THEN 1 ELSE 0 END) as failed_count"),
            ])
            ->get()
            ->keyBy(fn ($row) => (int) $row->tenant_id)
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function terminalMetrics(string $from, string $to, array $tenantIds, ?int $terminalId): array
    {
        if (! Schema::hasTable('pos_terminals')) {
            return [];
        }

        return DB::table('pos_terminals')
            ->leftJoin('transactions', function ($join) use ($from, $to) {
                $join->on('transactions.terminal_id', '=', 'pos_terminals.id')
                    ->whereBetween('transactions.transaction_timestamp', [$from, $to]);
            })
            ->whereIn('pos_terminals.tenant_id', $tenantIds)
            ->when($terminalId !== null, fn (Builder $query) => $query->where('pos_terminals.id', $terminalId))
            ->groupBy('pos_terminals.tenant_id')
            ->select([
                'pos_terminals.tenant_id',
                DB::raw('COUNT(DISTINCT pos_terminals.id) as total_terminals'),
                DB::raw('COUNT(DISTINCT CASE WHEN pos_terminals.is_active = 1 AND pos_terminals.status_id = 1 THEN pos_terminals.id END) as active_terminals'),
                DB::raw('COUNT(DISTINCT CASE WHEN transactions.id IS NULL THEN pos_terminals.id END) as terminals_without_tx'),
                DB::raw('MAX(pos_terminals.last_seen_at) as last_seen_at'),
            ])
            ->get()
            ->keyBy(fn ($row) => (int) $row->tenant_id)
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function flags(array $tx, array $sub, array $q, array $in, array $term): array
    {
        $flags = [];
        $txCount = (int) ($tx['count'] ?? 0);
        $activity = (int) ($sub['count'] ?? 0) + (int) ($q['count'] ?? 0) + (int) ($in['count'] ?? 0);

        if ($txCount === 0 && $activity > 0) {
            $flags[] = 'NO_PERSISTED_TX_WITH_ACTIVITY';
        }

        if ($txCount === 0 && $activity === 0 && (int) ($term['active_terminals'] ?? 0) > 0) {
            $flags[] = 'NO_ACTIVITY';
        }

        if ((int) ($tx['tenant_terminal_drift'] ?? 0) > 0) {
            $flags[] = 'TENANT_TERMINAL_DRIFT';
        }

        if ((int) ($q['count'] ?? 0) > 0) {
            $flags[] = 'HAS_QUARANTINE';
        }

        if ((int) ($tx['pending_count'] ?? 0) > 0 || (int) ($tx['invalid_or_failed_count'] ?? 0) > 0 || (int) ($tx['job_failed_count'] ?? 0) > 0) {
            $flags[] = 'FAILED_OR_PENDING_TX';
        }

        if ((int) ($in['rejected_count'] ?? 0) > 0 || (int) ($in['failed_count'] ?? 0) > 0) {
            $flags[] = 'INTAKE_REJECTIONS_OR_FAILURES';
        }

        if ($txCount > 0 && (int) ($term['terminals_without_tx'] ?? 0) > 0) {
            $flags[] = 'SOME_TERMINALS_WITHOUT_TX';
        }

        return $flags;
    }
}
