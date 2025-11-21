<?php

namespace App\Http\Controllers\Api\Webapp;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HourlyTransactionsController extends Controller
{
    /**
     * GET /api/v1/webapp/transactions/hourly
     *
     * Query params:
     *  - tenant_id (optional)
     *  - terminal_id (optional)
     *  - date_from (required, Y-m-d)
     *  - date_to (required, Y-m-d)
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'tenant_id'   => ['nullable', 'string', 'max:64'],
            'terminal_id' => ['nullable', 'string', 'max:64'],
            'date_from'   => ['required', 'date'],
            'date_to'     => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $dateFrom = $validated['date_from'];
        $dateTo = $validated['date_to'];

        // Use reporting connection for read-only summary access
        $conn = DB::connection('reporting');

        try {
            $schema = $conn->getSchemaBuilder();
        } catch (\Throwable $e) {
            // Fallback to default connection if reporting connection misconfigured
            Log::warning('Reporting connection unavailable, falling back to primary DB for hourly report', ['error' => $e->getMessage()]);
            $conn = DB::connection();
            $schema = $conn->getSchemaBuilder();
        }

        $table = 'transactions_hourly';

        // base selects (always expected)
        $select = [
            $table . '.tenant_id',
            $table . '.terminal_id',
            $table . '.hour',
            $table . '.tx_count',
            $table . '.total_amount',
        ];

        // optional fields to include when present in schema
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

        // Join tenants for customer_code/trade_name/location/zone when available
        $joinTenants = $schema->hasTable('tenants');
        if ($joinTenants) {
            // prefer tenant fields: customer_code, trade_name, location, zone
            $select[] = 'tenants.customer_code as customer_code';
            // trade_name may be the display name
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

        if (! empty($validated['tenant_id'])) {
            $query->where($table . '.tenant_id', $validated['tenant_id']);
        }
        if (! empty($validated['terminal_id'])) {
            $query->where($table . '.terminal_id', $validated['terminal_id']);
        }

        // If we joined tenants, apply the join
        if (!empty($joinTenants)) {
            $query->leftJoin('tenants', $table . '.tenant_id', '=', 'tenants.id');
        }

        // Safety limit to avoid returning huge datasets
        $rows = $query->orderBy($table . '.tenant_id')->orderBy($table . '.terminal_id')->orderBy($table . '.hour')->limit(1000)->get();

        // Map results into the contract shape and ensure numeric types for amounts
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
        })->values();

        return response()->json(['data' => $data]);
    }
}
