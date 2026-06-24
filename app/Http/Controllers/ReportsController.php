<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Reports\SalesReportDataService;
use App\Services\Reports\SalesReportFilter;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    /**
     * Display a simple reports landing page for finance users.
     */
    public function index(Request $request)
    {
        $tenants = Tenant::orderBy('trade_name')->get(['id', 'trade_name'])->pluck('trade_name', 'id')->toArray();
        $selected_tenant = $request->get('tenant', '');

        return view('reports.dashboard', compact('tenants', 'selected_tenant'));
    }

    /**
     * JSON endpoint returning daily totals for a given tenant and month.
     */
    public function data(Request $request, SalesReportDataService $salesReportData)
    {
        $filter = SalesReportFilter::forTenantMonth(
            $request->query('tenant', $request->query('trade', null)),
            $request->query('month', now()->format('Y-m')),
        );

        return response()->json($salesReportData->getCmsrReportData($filter)->toArray());
    }
}
