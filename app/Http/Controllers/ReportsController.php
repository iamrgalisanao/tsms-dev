<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Arr;

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

        $query->whereYear('created_at', $year)
              ->whereMonth('created_at', $month)
              ->orderBy('created_at');

        $transactions = $query->get();

        $byDate = $transactions
            ->groupBy(fn($tx) => $tx->created_at->format('Y-m-d'))
            ->map(function($group) {
                return [
                    'net_sales'     => $group->sum('net_sales'),
                    'vat_exempt_sales' => $group->sum('vat_exempt_sales'),
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
