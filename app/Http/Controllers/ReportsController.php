<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class ReportsController extends Controller
{
    /**
     * Display a simple reports landing page for finance users.
     */
    public function index(Request $request)
    {
        // Finance dashboard - loads interactive widgets that call the Webapp API
        // Provide tenant list and selected tenant for the view
        $tenants = Tenant::orderBy('trade_name')->get(['id','trade_name'])->pluck('trade_name', 'id')->toArray();
        $selected_tenant = $request->get('tenant', '');

    // Render the finance reports dashboard view. Use the consolidated
    // `reports.dashboard` view which contains the Finance Reports UI and
    // shared chart/table scripts (resources/views/reports/dashboard.blade.php).
    return view('reports.dashboard', compact('tenants', 'selected_tenant'));
    }

    /**
     * JSON endpoint returning daily totals for a given tenant and month.
     * Returns shape expected by the reports dashboard JS.
     */
    public function data(Request $request)
    {
        // Accept either 'tenant' or legacy 'trade' parameter from the JS
        $tenant = $request->query('tenant', $request->query('trade', null));

        // Accept month as either 'MM' or 'YYYY-MM' (the UI uses <input type="month">)
        $rawMonth = $request->query('month', now()->format('m'));
        $year = (int) $request->query('year', now()->year);
        $month = null;
        if (is_string($rawMonth) && str_contains($rawMonth, '-')) {
            // format: YYYY-MM
            [$y, $m] = explode('-', $rawMonth) + [null, null];
            $year = (int) ($y ?? $year);
            $month = str_pad(($m ?? now()->format('m')), 2, '0', STR_PAD_LEFT);
        } else {
            $month = str_pad($rawMonth, 2, '0', STR_PAD_LEFT);
        }

        $query = Transaction::query();
        if ($tenant && $tenant !== 'all') {
            $query->where('tenant_id', $tenant);
        }
        // Prefer filtering by canonical transaction_timestamp when the column exists
        // to align the finance reports with admin transaction logs and reporting
        // services. Fall back to created_at when transaction_timestamp is missing.
        if (Schema::hasColumn('transactions', 'transaction_timestamp')) {
            $query->whereYear('transaction_timestamp', $year)
                  ->whereMonth('transaction_timestamp', $month)
                  ->orderBy('transaction_timestamp');
        } else {
            $query->whereYear('created_at', $year)
                  ->whereMonth('created_at', $month)
                  ->orderBy('created_at');
        }

        $transactions = $query->get();

        // Group transactions by the canonical date (transaction_timestamp if present,
        // otherwise completed_at/created_at) to ensure daily buckets align with
        // the reporting services (which use COALESCE(transaction_timestamp, completed_at, created_at)).
        $byDate = $transactions
            ->groupBy(function($tx) {
                $ts = $tx->transaction_timestamp ?? $tx->completed_at ?? $tx->created_at;
                try {
                    return Carbon::parse($ts)->format('Y-m-d');
                } catch (\Throwable $_) {
                    // if parsing fails, fall back to created_at string format
                    return optional($tx->created_at)->format('Y-m-d') ?? date('Y-m-d');
                }
            })
            ->map(function($group) {
                return [
                    'net_sales'     => $group->sum('net_sales'),
                    // The DB column is `sc_vat_exempt_sales`; expose it to the UI
                    // as `vat_exempt_sales` to keep front-end keys stable.
                    'vat_exempt_sales' => $group->sum('sc_vat_exempt_sales'),
                    'promo_with_approval' => $group->sum('promo_with_approval'),
                    'promo_without_approval' => $group->sum('promo_without_approval'),
                    'employee_discount' => $group->sum('employee_discount'),
                    'senior_discount' => $group->sum('senior_discount'),
                    'pwd_discount' => $group->sum('pwd_discount'),
                    'vip_discount' => $group->sum('vip_discount'),
                    'other_tax' => $group->sum('other_tax'),
                    'service_charge_distributed' => $group->sum('service_charge_distributed'),
                    'service_charge_retained' => $group->sum('service_charge_retained'),
                    'gross_sales' => $group->sum('gross_sales'),
                ];
            })
            ->toArray();

        // build totals
        $totals = array_reduce($byDate, function($carry, $day) {
            foreach ($day as $k => $v) {
                $carry[$k] = ($carry[$k] ?? 0) + $v;
            }
            return $carry;
        }, []);

        return response()->json([
            'status' => 'success',
            'year' => $year,
            'month' => $month,
            'daily_totals' => $byDate,
            'totals' => $totals,
        ]);
    }
}
