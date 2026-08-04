<?php

namespace App\Http\Controllers\API\Webapp;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportsController extends Controller
{
    /**
     * Return aggregated sales over a period (hourly/daily/weekly/monthly/yearly).
     */
    public function sales(Request $request)
    {
        $period = $request->query('period', 'daily');
        $start = $request->query('start');
        $end = $request->query('end');
        $tenantId = $request->query('tenant_id');
        $terminalId = $request->query('terminal_id');

        // Validate basic params
        if (! in_array($period, ['hourly', 'daily', 'weekly', 'monthly', 'yearly'], true)) {
            return response()->json(['message' => 'Invalid period'], 422);
        }

        // Choose source table/aggregation
        $conn = DB::connection('reporting');

        if ($period === 'hourly') {
            $table = 'transactions_hourly';

            // Build a defensive select list based on which columns exist in the
            // reporting summary table. This avoids hard failures when optional
            // aggregates are absent in a given environment.
            $schema = $conn->getSchemaBuilder();

            $selectParts = [
                'hour as period_start',
                'tenant_id',
                'terminal_id',
                'tx_count',
                'total_amount as gross_sales',
            ];

            // Optional aggregate columns added by migration
            if ($schema->hasColumn($table, 'total_gross_amount')) {
                $selectParts[] = 'total_gross_amount';
            }
            if ($schema->hasColumn($table, 'total_net_amount')) {
                $selectParts[] = 'total_net_amount';
            }
            if ($schema->hasColumn($table, 'total_discount_amount')) {
                $selectParts[] = 'total_discount_amount';
            }
            if ($schema->hasColumn($table, 'total_tax_amount')) {
                $selectParts[] = 'total_tax_amount';
            }
            if ($schema->hasColumn($table, 'total_service_charge_amount')) {
                $selectParts[] = 'total_service_charge_amount';
            }
            if ($schema->hasColumn($table, 'void_count')) {
                $selectParts[] = 'void_count';
            }
            if ($schema->hasColumn($table, 'refunded_count')) {
                $selectParts[] = 'refunded_count';
            }

            // Optionally include small human-friendly references from
            // tenants/pos_terminals if they exist in the reporting DB.
            $joinTenants = $schema->hasTable('tenants');
            $joinTerminals = $schema->hasTable('pos_terminals');
            if ($joinTenants) {
                $selectParts[] = 'tenants.name as tenant_name';
            }
            if ($joinTerminals) {
                $selectParts[] = 'pos_terminals.serial_number as terminal_uid';
                // include a zone/label if available
                if ($schema->hasColumn('pos_terminals', 'zone')) {
                    $selectParts[] = 'pos_terminals.zone as terminal_zone';
                }
            }

            $query = $conn->table($table)->selectRaw(implode(', ', $selectParts));

            if ($joinTenants) {
                $query->leftJoin('tenants', $table . '.tenant_id', '=', 'tenants.id');
            }
            if ($joinTerminals) {
                $query->leftJoin('pos_terminals', $table . '.terminal_id', '=', 'pos_terminals.id');
            }

            if ($tenantId) {
                $query->where($table . '.tenant_id', $tenantId);
            }
            if ($terminalId) {
                $query->where($table . '.terminal_id', $terminalId);
            }
            if ($start) {
                $query->where($table . '.hour', '>=', $start);
            }
            if ($end) {
                $query->where($table . '.hour', '<=', $end);
            }

            $rows = $query->orderBy($table . '.hour', 'asc')->get();
        } else {
            // Use daily as base for daily/weekly/monthly/yearly
            $table = 'transactions_daily';
            $query = $conn->table($table)->selectRaw('date as period_start, tx_count, total_amount');
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }
            if ($terminalId) {
                $query->where('terminal_id', $terminalId);
            }
            if ($start) {
                $query->where('date', '>=', $start);
            }
            if ($end) {
                $query->where('date', '<=', $end);
            }
            // For daily we just return the rows; for weekly/monthly/yearly we aggregate
            if ($period === 'daily') {
                $rows = $query->orderBy('date', 'asc')->get();
            } elseif ($period === 'weekly') {
                // Group by ISO week (mode=1 -> week starts Monday)
                $rows = $conn->table($table)
                    ->selectRaw('YEAR(`date`) as y, WEEK(`date`, 1) as w, MIN(`date`) as period_start, SUM(tx_count) as tx_count, SUM(total_amount) as total_amount')
                    ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
                    ->when($terminalId, fn($q) => $q->where('terminal_id', $terminalId))
                    ->when($start, fn($q) => $q->where('date', '>=', $start))
                    ->when($end, fn($q) => $q->where('date', '<=', $end))
                    ->groupBy('y', 'w')
                    ->orderBy('y', 'asc')
                    ->orderBy('w', 'asc')
                    ->get();
            } elseif ($period === 'monthly') {
                $rows = $conn->table($table)
                    ->selectRaw('YEAR(`date`) as y, MONTH(`date`) as m, MIN(`date`) as period_start, SUM(tx_count) as tx_count, SUM(total_amount) as total_amount')
                    ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
                    ->when($terminalId, fn($q) => $q->where('terminal_id', $terminalId))
                    ->when($start, fn($q) => $q->where('date', '>=', $start))
                    ->when($end, fn($q) => $q->where('date', '<=', $end))
                    ->groupBy('y', 'm')
                    ->orderBy('y', 'asc')
                    ->orderBy('m', 'asc')
                    ->get();
            } else { // yearly
                $rows = $conn->table($table)
                    ->selectRaw('YEAR(`date`) as y, MIN(`date`) as period_start, SUM(tx_count) as tx_count, SUM(total_amount) as total_amount')
                    ->when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
                    ->when($terminalId, fn($q) => $q->where('terminal_id', $terminalId))
                    ->when($start, fn($q) => $q->where('date', '>=', $start))
                    ->when($end, fn($q) => $q->where('date', '<=', $end))
                    ->groupBy('y')
                    ->orderBy('y', 'asc')
                    ->get();
            }
        }

        $data = $rows->map(function ($r) {
            return [
                'period_start' => \Carbon\Carbon::parse($r->period_start)->toIso8601String(),
                'tenant_id' => $r->tenant_id ?? null,
                'tenant_name' => $r->tenant_name ?? null,
                'terminal_id' => $r->terminal_id ?? null,
                'terminal_uid' => $r->terminal_uid ?? null,
                'terminal_zone' => $r->terminal_zone ?? null,
                'transactions' => (int) ($r->tx_count ?? 0),
                'gross_sales' => (float) ($r->gross_sales ?? $r->total_amount ?? 0),
                'total_gross_amount' => (float) ($r->total_gross_amount ?? 0),
                'total_net_amount' => (float) ($r->total_net_amount ?? 0),
                'total_discount_amount' => (float) ($r->total_discount_amount ?? 0),
                'total_tax_amount' => (float) ($r->total_tax_amount ?? 0),
                'total_service_charge_amount' => (float) ($r->total_service_charge_amount ?? 0),
                'void_count' => (int) ($r->void_count ?? 0),
                'refunded_count' => (int) ($r->refunded_count ?? 0),
            ];
        })->values();

        return response()->json([
            'period' => $period,
            'start' => $start,
            'end' => $end,
            'data' => $data,
            'meta' => [
                'resolution' => $period,
                'generated_at' => now()->toIso8601String(),
                'source' => 'summary_tables'
            ]
        ]);
    }

    /**
     * Drilldown: paginated list for a given date/hour (transactions or aggregated buckets)
     */
    public function drilldown(Request $request)
    {
        $date = $request->query('date');
        $tenantId = $request->query('tenant_id');
        $perPage = min( (int) $request->query('per_page', 15), 100);
        $page = max(1, (int) $request->query('page', 1));

        if (! $date) {
            return response()->json(['message' => 'date parameter required'], 422);
        }
        $reporting = DB::connection('reporting');

        // If the reporting DB contains a raw `transactions` table, return paginated transactions there.
        try {
            $schema = $reporting->getSchemaBuilder();
            if ($schema->hasTable('transactions')) {
                $query = $reporting->table('transactions')->whereDate('created_at', $date);
                if ($tenantId) {
                    $query->where('tenant_id', $tenantId);
                }

                $total = $query->count();
                $rows = $query->orderBy('created_at', 'desc')->forPage($page, $perPage)->get();

                return response()->json([
                    'data' => $rows,
                    'links' => [
                        'first' => url()->current() . '?page=1',
                        'last' => url()->current() . '?page=' . max(1, ceil($total / $perPage)),
                        'prev' => $page > 1 ? url()->current() . '?page=' . ($page - 1) : null,
                        'next' => $page * $perPage < $total ? url()->current() . '?page=' . ($page + 1) : null,
                    ],
                    'meta' => [
                        'current_page' => $page,
                        'from' => ($page - 1) * $perPage + 1,
                        'last_page' => max(1, ceil($total / $perPage)),
                        'path' => url()->current(),
                        'per_page' => $perPage,
                        'to' => min($page * $perPage, $total),
                        'total' => $total,
                    ],
                ]);
            }
        } catch (\Throwable $e) {
            // Fall back to summary buckets if the reporting DB doesn't expose raw transactions
        }

        // Fallback: return the hourly/daily bucket sample stored in summary tables.
        // If $date includes a time component, match exact hour; otherwise match date portion.
        $hourlyTable = 'transactions_hourly';
        $q = $reporting->table($hourlyTable)->select([
            'tenant_id', 'terminal_id', 'hour', 'tx_count', 'total_amount', 'total_gross_amount', 'total_net_amount', 'total_discount_amount', 'total_tax_amount', 'total_service_charge_amount', 'void_count', 'refunded_count', 'sample_transaction_id', 'sample_completed_at', 'sample_payment_method', 'sample_channel', 'sample_primary_category'
        ]);
        if ($tenantId) {
            $q->where('tenant_id', $tenantId);
        }

        // detect if date is an hour-like value (contains a space or T or colon)
        if (strpos($date, 'T') !== false || strpos($date, ' ') !== false || strpos($date, ':') !== false) {
            $q->where('hour', $date);
        } else {
            $q->whereDate('hour', $date);
        }

        $row = $q->first();
        if (! $row) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }

        // Build a single-item response that contains the bucket counts and a sample transaction detail
        $sample = [
            'sample_transaction_id' => $row->sample_transaction_id,
            'sample_completed_at' => $row->sample_completed_at ? \Carbon\Carbon::parse($row->sample_completed_at)->toIso8601String() : null,
            'sample_payment_method' => $row->sample_payment_method,
            'sample_channel' => $row->sample_channel,
            'sample_primary_category' => $row->sample_primary_category,
        ];

        // If we have a sample_transaction_id, try to resolve it into a full
        // transaction payload from the primary (authoritative) DB so the
        // Webapp can display full details for drilldown. This is optional and
        // best-effort: failures are silently ignored and the sample remains.
        if (! empty($row->sample_transaction_id)) {
            try {
                $primary = DB::connection();
                $tx = $primary->table('transactions')->where('id', $row->sample_transaction_id)->first();
                if ($tx) {
                    // Convert stdClass -> associative array for JSON friendliness
                    $sample['transaction'] = (array) $tx;
                } else {
                    $sample['transaction'] = null;
                }
            } catch (\Throwable $e) {
                // don't fail the entire endpoint for a lookup error
                $sample['transaction'] = null;
            }
        } else {
            $sample['transaction'] = null;
        }

        $payload = [
            'bucket' => [
                'tenant_id' => $row->tenant_id,
                'terminal_id' => $row->terminal_id,
                'hour' => \Carbon\Carbon::parse($row->hour)->toIso8601String(),
                'transactions' => (int) $row->tx_count,
                'gross_sales' => (float) $row->total_amount,
                'total_gross_amount' => (float) ($row->total_gross_amount ?? 0),
                'total_net_amount' => (float) ($row->total_net_amount ?? 0),
                'total_discount_amount' => (float) ($row->total_discount_amount ?? 0),
                'total_tax_amount' => (float) ($row->total_tax_amount ?? 0),
                'total_service_charge_amount' => (float) ($row->total_service_charge_amount ?? 0),
                'void_count' => (int) ($row->void_count ?? 0),
                'refunded_count' => (int) ($row->refunded_count ?? 0),
            ],
            'sample' => $sample,
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'source' => 'summary_tables'
            ]
        ];

        return response()->json($payload);
    }

    /**
     * Summary totals for dashboard badges.
     */
    public function summary(Request $request)
    {
        $tenantId = $request->query('tenant_id');
        $conn = DB::connection('reporting');

        $today = $conn->table('transactions_daily')->where('date', now()->toDateString());
        if ($tenantId) {
            $today->where('tenant_id', $tenantId);
        }
        $todayRow = $today->first();

        $result = [
            'today' => [
                'gross_sales' => $todayRow->total_amount ?? 0,
                'transactions' => $todayRow->tx_count ?? 0,
            ],
            'generated_at' => now()->toIso8601String(),
        ];

        return response()->json($result);
    }
}
