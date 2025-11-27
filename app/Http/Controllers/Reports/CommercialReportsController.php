<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Http\Controllers\Api\Webapp\HourlyTransactionsController as ApiHourlyController;
use App\Http\Controllers\Finance\SalesReportExportController as FinanceExportController;
use App\Services\Reports\HourlyReportService;
use App\Services\Reports\DailyReportService;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;

class CommercialReportsController extends Controller
{
    // Show hourly report UI
    public function hourly()
    {
        return view('reports.commercial.hourly');
    }

    // Show daily report UI
    public function daily()
    {
        return view('reports.commercial.daily');
    }

    // Show weekly report UI
    public function weekly()
    {
        return view('reports.commercial.weekly');
    }

    // Show weekday report UI
    public function weekday()
    {
        return view('reports.commercial.weekday');
    }

    // Show weekend report UI
    public function weekend()
    {
        return view('reports.commercial.weekend');
    }

    // Show monthly report UI
    public function monthly()
    {
        return view('reports.commercial.monthly');
    }

    // Show yearly report UI
    public function yearly()
    {
        return view('reports.commercial.yearly');
    }

    /**
     * Return a JSON list of tenants for the commercial reports dropdown.
     */
    public function tenants(Request $request)
    {
        $tenants = Tenant::orderBy('trade_name')
            ->get(['id', 'trade_name', 'customer_code'])
            ->map(function ($t) {
                return [
                    'id' => $t->id,
                    'trade_name' => $t->trade_name,
                    'customer_code' => $t->customer_code ?? '',
                ];
            })->values();

        return response()->json($tenants);
    }

    /**
     * Proxy endpoint for hourly transactions so the web UI can call a web-authenticated route
     * and we can adapt the single-date UI param to the API's date_from/date_to contract.
     */
    public function hourlyData(Request $request)
    {
        $request->validate([
            'date' => ['required', 'date'],
            'tenant_id' => ['required']
        ]);

        $date = $request->input('date');
        $tenantId = $request->input('tenant_id');

        // Use HourlyReportService (direct call) to avoid controller-to-controller calls
        $service = new HourlyReportService();
        $data = $service->getHourlyAggregates($date, $date, $tenantId, null);

        // Record a lightweight audit event so UI "Load Report" actions are visible in audit logs.
        try {
            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'report.hourly_view',
                'action_type' => 'view',
                'resource_type' => 'report',
                'resource_id' => null,
                'ip_address' => $request->ip(),
                'message' => "Viewed hourly report for {$date}",
                'old_values' => null,
                'new_values' => null,
                'metadata' => ['date' => $date, 'tenant_id' => $tenantId],
                'logged_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Don't let audit failures block the UI; log for ops.
            Log::warning('Failed to write AuditLog for hourly report view: ' . $e->getMessage(), ['date' => $date, 'tenant' => $tenantId]);
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Proxy endpoint for daily summary used by the daily sales UI.
     * Accepts a single 'date' and 'tenant_id' and returns a summary + hourly breakdown.
     */
    public function dailyData(Request $request)
    {
        $request->validate([
            'date' => ['required', 'date'],
            'tenant_id' => ['required']
        ]);

        $date = $request->input('date');
        $tenantId = $request->input('tenant_id');

        $service = new DailyReportService();
        $result = $service->getDailySummary($date, $tenantId, null);

        // Audit the UI action (non-blocking)
        try {
            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'report.daily_view',
                'action_type' => 'view',
                'resource_type' => 'report',
                'resource_id' => null,
                'ip_address' => $request->ip(),
                'message' => "Viewed daily report for {$date}",
                'old_values' => null,
                'new_values' => null,
                'metadata' => ['date' => $date, 'tenant_id' => $tenantId],
                'logged_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write AuditLog for daily report view: ' . $e->getMessage(), ['date' => $date, 'tenant' => $tenantId]);
        }

        return response()->json($result);
    }

    /**
     * Export proxy that accepts a single date and tenant_id and adapts them to the
     * finance export controller which expects year and month parameters.
     */
    public function exportProxy(Request $request)
    {
        $request->validate([
            'date' => ['required', 'date'],
            'tenant_id' => ['required']
        ]);

        $date = \Carbon\Carbon::parse($request->input('date'));
        $year = $date->year;
        $month = str_pad($date->month, 2, '0', STR_PAD_LEFT);
        $tenant = $request->input('tenant_id');

        // Build a sub-request for the finance export controller
        $apiRequest = Request::create('/finance/reports/export', 'GET', [
            'year' => $year,
            'month' => $month,
            'tenant' => $tenant,
        ]);

        $exportController = new FinanceExportController();
        return $exportController->export($apiRequest);
    }
}
