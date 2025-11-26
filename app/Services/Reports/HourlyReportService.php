<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Service that encapsulates the hourly aggregation logic used by both
 * the API and the web (commercial) controllers.
 */
class HourlyReportService
{
    protected $connectionName = 'reporting';

    /**
     * Fetch hourly aggregates for the given date range and optional tenant/terminal filters.
     * Returns a collection (array) of normalized rows with the same contract
     * as Api\Webapp\HourlyTransactionsController produced previously.
     *
     * @param string $dateFrom Y-m-d
     * @param string $dateTo   Y-m-d
     * @param string|null $tenantId
     * @param string|null $terminalId
     * @return array
     */
    public function getHourlyAggregates(string $dateFrom, string $dateTo, ?string $tenantId = null, ?string $terminalId = null): array
    {
        // Prefer reporting connection but fall back if not configured
        try {
            $conn = DB::connection($this->connectionName);
            $schema = $conn->getSchemaBuilder();
        } catch (\Throwable $e) {
            $conn = DB::connection();
            $schema = $conn->getSchemaBuilder();
        }

        $table = 'transactions_hourly';

        $select = [
            $table . '.tenant_id',
            $table . '.terminal_id',
            $table . '.hour',
            $table . '.tx_count as tx_count',
            $table . '.total_amount as total_amount',
        ];

        $optional = [
            'total_gross_amount',
            'total_net_amount',
            'total_discount_amount',
            'total_tax_amount',
            'total_service_charge_amount',
            'avg_amount',
            'min_amount',
            'max_amount',
            'success_count',
            'decline_count',
            'issues_count',
            'issues_amount',
            'void_count',
            'refunded_count',
            'duplicate_count',
        ];

        foreach ($optional as $col) {
            if ($schema->hasColumn($table, $col)) {
                $select[] = $table . '.' . $col;
            }
        }

        $joinTenants = $schema->hasTable('tenants');
        if ($joinTenants) {
            $select[] = 'tenants.customer_code as customer_code';
            if ($schema->hasColumn('tenants', 'trade_name')) {
                $select[] = 'tenants.trade_name as tenant_name';
            } else {
                $select[] = 'tenants.name as tenant_name';
            }
            if ($schema->hasColumn('tenants', 'location')) {
                $select[] = 'tenants.location as location';
            }
            if ($schema->hasColumn('tenants', 'zone')) {
                $select[] = 'tenants.zone as zone';
            }
        }

        $query = $conn->table($table)->select($select)
            ->whereDate('hour', '>=', $dateFrom)
            ->whereDate('hour', '<=', $dateTo);

        if (! empty($tenantId)) {
            $query->where($table . '.tenant_id', $tenantId);
        }
        if (! empty($terminalId)) {
            $query->where($table . '.terminal_id', $terminalId);
        }

        if (!empty($joinTenants)) {
            $query->leftJoin('tenants', $table . '.tenant_id', '=', 'tenants.id');
        }

        $rows = $query->orderBy($table . '.tenant_id')->orderBy($table . '.terminal_id')->orderBy($table . '.hour')->limit(1000)->get();

        $data = $rows->map(function ($r) {
            return [
                'customer_code' => $r->customer_code ?? null,
                'tenant_name' => $r->tenant_name ?? null,
                'location' => $r->location ?? null,
                'zone' => $r->zone ?? null,
                'sales_date' => \Carbon\Carbon::parse($r->hour)->setTimezone(config('app.timezone'))->toDateString(),
                'hour' => \Carbon\Carbon::parse($r->hour)->setTimezone(config('app.timezone'))->format('H:00'),
                'gross_sales' => isset($r->total_gross_amount) ? (float) $r->total_gross_amount : (isset($r->total_amount) ? (float) $r->total_amount : 0.0),
                'vatable_sales' => isset($r->total_net_amount) ? (float) $r->total_net_amount : 0.0,
                'vat_exempt_sales' => 0.0,
                'vat_amount' => isset($r->total_tax_amount) ? (float) $r->total_tax_amount : 0.0,
                'sc_pwd_discount' => 0.0,
                'regular_discount' => isset($r->total_discount_amount) ? (float) $r->total_discount_amount : 0.0,
                'void' => 0.0,
                'return' => 0.0,
                'net_sales' => isset($r->total_net_amount) ? (float) $r->total_net_amount : 0.0,
                'cash_payment' => 0.0,
                'card_payment' => 0.0,
                'other_tender' => 0.0,
                'net_sales_percentage_rent' => 0.0,
                'transaction_count' => (int) ($r->tx_count ?? 0),
                'guest_count' => 0,
            ];
        })->values()->toArray();

        return $data;
    }
}
