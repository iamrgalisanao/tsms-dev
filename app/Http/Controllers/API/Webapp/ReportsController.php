<?php

namespace App\Http\Controllers\Api\Webapp;

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
            $select = ['hour as period_start', 'tx_count', 'total_amount'];
            $query = $conn->table($table)->selectRaw('hour as period_start, tx_count, total_amount');
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }
            if ($terminalId) {
                $query->where('terminal_id', $terminalId);
            }
            if ($start) {
                $query->where('hour', '>=', $start);
            }
            if ($end) {
                $query->where('hour', '<=', $end);
            }
            $rows = $query->orderBy('hour', 'asc')->get();
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
                'transactions' => (int) ($r->tx_count ?? 0),
                'gross_sales' => (float) ($r->total_amount ?? 0),
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

        // For simplicity return transactions from the main table for drilldown
        $conn = DB::connection('reporting');
        $query = $conn->table('transactions')->whereDate('created_at', $date);
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
