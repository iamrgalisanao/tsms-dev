<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Http\Controllers\Api\Webapp\HourlyTransactionsController as ApiHourlyController;
use App\Http\Controllers\Finance\SalesReportExportController as FinanceExportController;
use App\Services\Reports\HourlyReportService;
use App\Services\Reports\DailyReportService;
use App\Services\Reports\WeeklyReportService;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;

class CommercialReportsController extends Controller
{
    // Show hourly report UI
    public function hourly()
    {
        return view('reports.commercial.hourly');
    }

    // Show commercial dashboard UI (charts for daily/weekly/monthly/yearly)
    public function dashboard()
    {
        return view('reports.commercial.dashboard');
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

    /**
     * Proxy endpoint for weekly summary used by the weekly sales UI.
     * Accepts 'date_from' and 'date_to' and returns per-day aggregates.
     */
    public function weeklyData(Request $request)
    {
        $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            // tenant_id may be omitted to request "All Tenants" aggregates from the service
            'tenant_id' => ['nullable']
        ]);

        $from = $request->input('date_from');
        $to = $request->input('date_to');
        $tenantId = $request->input('tenant_id');

        $service = new WeeklyReportService();
        $result = $service->getWeeklySummary($from, $to, $tenantId, false, false, true);

        // Log result shape for debugging client 'No data' cases
        try {
            Log::info('commercial.weeklyData result', [
                'from' => $from,
                'to' => $to,
                'tenant' => $tenantId,
                'summary' => $result['summary'] ?? null,
                'days_count' => is_array($result['days'] ?? null) ? count($result['days']) : null,
                'sample_days' => array_slice($result['days'] ?? [], 0, 3),
            ]);
        } catch (\Throwable $__e) {
            Log::warning('Failed to log weeklyData debug info: ' . $__e->getMessage());
        }

        try {
            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'report.weekly_view',
                'action_type' => 'view',
                'resource_type' => 'report',
                'resource_id' => null,
                'ip_address' => $request->ip(),
                'message' => "Viewed weekly report for {$from} to {$to}",
                'old_values' => null,
                'new_values' => null,
                'metadata' => ['date_from' => $from, 'date_to' => $to, 'tenant_id' => $tenantId],
                'logged_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write AuditLog for weekly report view: ' . $e->getMessage(), ['from' => $from, 'to' => $to, 'tenant' => $tenantId]);
        }

        return response()->json($result);
    }

    /**
     * Proxy endpoint for weekday summary (Monday-Friday) used by the weekday sales UI.
     * Behaves like weeklyData but excludes weekend dates from the aggregation.
     */
    public function weekdayData(Request $request)
    {
        $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'tenant_id' => ['nullable']
        ]);

        $from = $request->input('date_from');
        $to = $request->input('date_to');
        $tenantId = $request->input('tenant_id');

        $service = new WeeklyReportService();
        $result = $service->getWeeklySummary($from, $to, $tenantId, true, false, true);

        try {
            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'report.weekday_view',
                'action_type' => 'view',
                'resource_type' => 'report',
                'resource_id' => null,
                'ip_address' => $request->ip(),
                'message' => "Viewed weekday report for {$from} to {$to}",
                'old_values' => null,
                'new_values' => null,
                'metadata' => ['date_from' => $from, 'date_to' => $to, 'tenant_id' => $tenantId],
                'logged_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write AuditLog for weekday report view: ' . $e->getMessage(), ['from' => $from, 'to' => $to, 'tenant' => $tenantId]);
        }

        return response()->json($result);
    }

    /**
     * Proxy endpoint for weekend summary (Saturday & Sunday) used by the weekend sales UI.
     * Behaves like weeklyData but includes only weekend dates from the aggregation.
     */
    public function weekendData(Request $request)
    {
        $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'tenant_id' => ['nullable']
        ]);

        $from = $request->input('date_from');
        $to = $request->input('date_to');
        $tenantId = $request->input('tenant_id');

        $service = new WeeklyReportService();
        $result = $service->getWeeklySummary($from, $to, $tenantId, false, true, true);

        try {
            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'report.weekend_view',
                'action_type' => 'view',
                'resource_type' => 'report',
                'resource_id' => null,
                'ip_address' => $request->ip(),
                'message' => "Viewed weekend report for {$from} to {$to}",
                'old_values' => null,
                'new_values' => null,
                'metadata' => ['date_from' => $from, 'date_to' => $to, 'tenant_id' => $tenantId],
                'logged_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write AuditLog for weekend report view: ' . $e->getMessage(), ['from' => $from, 'to' => $to, 'tenant' => $tenantId]);
        }

        return response()->json($result);
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

    /**
     * Proxy endpoint for monthly summary (per-day rows for a chosen month).
     * Accepts 'date_from' and 'date_to' (month bounds) and 'tenant_id'.
     */
    public function monthlyData(Request $request)
    {
        $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            'tenant_id' => ['nullable']
        ]);

        $from = $request->input('date_from');
        $to = $request->input('date_to');
        $tenantId = $request->input('tenant_id');

        $service = new WeeklyReportService();
        $result = $service->getWeeklySummary($from, $to, $tenantId, false, false, true);

        try {
            Log::info('commercial.monthlyData result', [
                'from' => $from,
                'to' => $to,
                'tenant' => $tenantId,
                'summary' => $result['summary'] ?? null,
                'rows_count' => is_array($result['days'] ?? null) ? count($result['days']) : null,
                'sample_rows' => array_slice($result['days'] ?? [], 0, 3),
            ]);
        } catch (\Throwable $__e) {
            Log::warning('Failed to log monthlyData debug info: ' . $__e->getMessage());
        }

        try {
            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'report.monthly_view',
                'action_type' => 'view',
                'resource_type' => 'report',
                'resource_id' => null,
                'ip_address' => $request->ip(),
                'message' => "Viewed monthly report for {$from} to {$to}",
                'old_values' => null,
                'new_values' => null,
                'metadata' => ['date_from' => $from, 'date_to' => $to, 'tenant_id' => $tenantId],
                'logged_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write AuditLog for monthly report view: ' . $e->getMessage(), ['from' => $from, 'to' => $to, 'tenant' => $tenantId]);
        }

        return response()->json($result);
    }

    // Show yearly report UI
    public function yearly()
    {
        return view('reports.commercial.yearly');
    }

    /**
     * Proxy endpoint for yearly summary (monthly rows for a selected year).
     * Accepts 'date_from' and 'date_to' (year bounds) and 'tenant_id'.
     */
    public function yearlyData(Request $request)
    {
        $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
            // allow empty tenant_id to request aggregates for all tenants
            'tenant_id' => ['nullable']
        ]);

        $from = $request->input('date_from');
        $to = $request->input('date_to');
        $tenantId = $request->input('tenant_id');

        $service = new WeeklyReportService();

        // Iterate month-by-month and aggregate per-month summaries
        try {
            $start = \Carbon\Carbon::parse($from)->startOfMonth();
            $end = \Carbon\Carbon::parse($to)->startOfMonth();

            $months = [];
            $summary = [
                'gross_sales' => 0.0,
                'net_sales' => 0.0,
                'transaction_count' => 0,
                'guest_count' => 0,
                'vatable_sales' => 0.0,
                'vat_exempt_sales' => 0.0,
                'vat_amount' => 0.0,
                'sc_pwd_discount' => 0.0,
                'regular_discount' => 0.0,
                'cash_payment' => 0.0,
                'card_payment' => 0.0,
                'other_tender' => 0.0,
            ];

            for ($d = $start; $d->lte($end); $d->addMonth()) {
                $mFrom = $d->copy()->startOfMonth()->format('Y-m-d');
                $mTo = $d->copy()->endOfMonth()->format('Y-m-d');
                $res = $service->getWeeklySummary($mFrom, $mTo, $tenantId, false, false, true);
                $s = $res['summary'] ?? ['gross_sales' => 0.0, 'net_sales' => 0.0, 'transaction_count' => 0, 'guest_count' => 0];

                foreach (['vatable_sales', 'vat_exempt_sales', 'vat_amount', 'sc_pwd_discount', 'regular_discount', 'cash_payment', 'card_payment', 'other_tender'] as $k) {
                    if (!isset($s[$k]))
                        $s[$k] = 0.0;
                }

                $months[] = array_merge(['month' => $d->format('Y-m')], $s);

                $summary['gross_sales'] += (float) ($s['gross_sales'] ?? 0.0);
                $summary['net_sales'] += (float) ($s['net_sales'] ?? 0.0);
                $summary['transaction_count'] += (int) ($s['transaction_count'] ?? 0);
                $summary['guest_count'] += (int) ($s['guest_count'] ?? 0);
                foreach (['vatable_sales', 'vat_exempt_sales', 'vat_amount', 'sc_pwd_discount', 'regular_discount', 'cash_payment', 'card_payment', 'other_tender'] as $k) {
                    $summary[$k] += (float) ($s[$k] ?? 0.0);
                }
            }

            foreach (['gross_sales', 'net_sales', 'vatable_sales', 'vat_exempt_sales', 'vat_amount', 'sc_pwd_discount', 'regular_discount', 'cash_payment', 'card_payment', 'other_tender'] as $k) {
                $summary[$k] = round($summary[$k], 2);
            }

            try {
                AuditLog::create([
                    'user_id' => auth()->id(),
                    'action' => 'report.yearly_view',
                    'action_type' => 'view',
                    'resource_type' => 'report',
                    'resource_id' => null,
                    'ip_address' => $request->ip(),
                    'message' => "Viewed yearly report for {$from} to {$to}",
                    'old_values' => null,
                    'new_values' => null,
                    'metadata' => ['date_from' => $from, 'date_to' => $to, 'tenant_id' => $tenantId],
                    'logged_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to write AuditLog for yearly report view: ' . $e->getMessage(), ['from' => $from, 'to' => $to, 'tenant' => $tenantId]);
            }

            Log::info('commercial.yearlyData result', [
                'from' => $from,
                'to' => $to,
                'tenant' => $tenantId,
                'summary' => $summary,
                'months_count' => count($months),
                'sample_months' => array_slice($months, 0, 3),
            ]);

            return response()->json(['summary' => $summary, 'months' => $months]);
        } catch (\Throwable $e) {
            Log::warning('YearlyData failed: ' . $e->getMessage(), ['from' => $from, 'to' => $to, 'tenant' => $tenantId]);
            return response()->json(['summary' => ['gross_sales' => 0.0, 'net_sales' => 0.0, 'transaction_count' => 0, 'guest_count' => 0], 'months' => []]);
        }
    }

    /**
     * Return a JSON list of tenants for the commercial reports dropdown.
     */
    public function tenants(Request $request)
    {
        // JSON payload used by AJAX dropdowns and the Tenant Directory (profile data)
        $jsonTenants = Tenant::orderBy('trade_name')
            ->get(['id', 'trade_name', 'customer_code', 'category', 'status', 'location', 'unit_no'])
            ->map(function ($t) {
                return [
                    'id' => $t->id,
                    'trade_name' => $t->trade_name,
                    'customer_code' => $t->customer_code ?? '',
                    'category' => match ($t->category) {
                        'F&B' => 'Food',
                        'Services' => 'Service',
                        default => 'Retail'
                    },
                    'status' => match ($t->status) {
                        'Operational' => 'Active',
                        'Not Operational' => 'Notice',
                        default => 'Active'
                    },
                    'location' => $t->location ?? '',
                    'unit_no' => $t->unit_no ?? '',
                    // Monthly sales placeholder for list (real aggregation would be too slow here)
                    'monthly_sales' => '450,000',
                ];
            })->values();

        try {
            Log::info('commercial.tenants result', ['count' => count($jsonTenants)]);
        } catch (\Throwable $__e) {
            Log::warning('Failed to log tenants debug info: ' . $__e->getMessage());
        }

        // Keep existing AJAX/json contract for other pages that call this endpoint.
        if ($request->wantsJson() || $request->ajax() || str_contains($request->header('Accept') ?? '', 'application/json')) {
            return response()->json($jsonTenants);
        }

        // For regular web requests, render a tenants listing page.
        // Previously we returned a paginated result to avoid rendering very large
        // tables; however, the UI now uses client-side DataTables for paging/search.
        // If tenant counts become very large this may need to revert to server
        // pagination or introduce server-side DataTables endpoints.
        $allTenants = Tenant::orderBy('trade_name')->get();
        return view('reports.commercial.tenants.index', ['tenants' => $allTenants]);
    }

    /**
     * Show tenant details page for a single tenant.
     * Returns JSON for the React UI or the Blade view for the initial load.
     */
    public function tenantShow(Request $request, $id)
    {
        $tenant = Tenant::with(['posTerminals', 'company'])->findOrFail($id);

        if ($request->wantsJson()) {
            // Calculate Current Month Sales
            $currentMonthStart = now()->startOfMonth();
            $currentMonthEnd = now()->endOfMonth();
            $monthSales = $tenant->posTerminals()->get()->map(function ($terminal) use ($currentMonthStart, $currentMonthEnd) {
                return $terminal->transactions()
                    ->whereBetween('transaction_timestamp', [$currentMonthStart, $currentMonthEnd])
                    ->where('validation_status', 'VALID')
                    ->sum('gross_sales');
            })->sum();

            // Calculate YTD Sales
            $yearStart = now()->startOfYear();
            $yearEnd = now()->endOfYear();
            $ytdSales = $tenant->posTerminals()->get()->map(function ($terminal) use ($yearStart, $yearEnd) {
                return $terminal->transactions()
                    ->whereBetween('transaction_timestamp', [$yearStart, $yearEnd])
                    ->where('validation_status', 'VALID')
                    ->sum('gross_sales');
            })->sum();

            // Fetch Recent Transactions
            $recentTransactions = \App\Models\Transaction::where('tenant_id', $id)
                ->orderBy('transaction_timestamp', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($tx) {
                    return [
                        'id' => $tx->id,
                        'date' => $tx->transaction_timestamp->format('Y-m-d H:i'),
                        'reference_no' => $tx->receipt_no ?? $tx->transaction_id ?? 'N/A',
                        'amount' => (float) $tx->gross_sales,
                        'status' => $tx->validation_status,
                    ];
                });

            // Prepare extra data for the profile
            $data = [
                'id' => $tenant->id,
                'trade_name' => $tenant->trade_name,
                'customer_code' => $tenant->customer_code ?: ($tenant->company->customer_code ?? 'N/A'),
                'name' => $tenant->company->company_name ?? 'N/A',
                'level' => str_contains($tenant->location, 'Level') ? trim(str_replace('Level', '', $tenant->location)) : '1',
                'unit_no' => $tenant->unit_no ?? 'N/A',
                'category' => $tenant->category ?? 'Retail',
                'ytd_sales' => round($ytdSales, 2),
                'month_sales' => round($monthSales, 2),
                'lease_expiry' => 'Dec 2026', // Mock for now as it's not in schema
                'transactions' => $recentTransactions,
            ];

            return response()->json($data);
        }

        return view('reports.commercial.tenants.show', compact('tenant'));
    }

    /**
     * Stream CSV export of tenants. Uses chunking to avoid high memory usage.
     */
    public function tenantsExport(Request $request)
    {
        // Only allow admin or manager users to perform tenant exports.
        $user = auth()->user();
        $allowed = false;
        if ($user) {
            if (method_exists($user, 'hasAnyRole')) {
                $allowed = $user->hasAnyRole(['admin', 'manager']);
            } elseif (isset($user->role)) {
                $r = is_object($user->role) ? strtolower($user->role->name ?? '') : strtolower($user->role ?? '');
                $allowed = in_array($r, ['admin', 'manager']);
            }
        }
        if (!$allowed) {
            abort(403, 'Unauthorized to export tenants');
        }
        $filename = 'tenants_' . now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = ['tenant_code', 'tenant_id', 'trade_name', 'location_type', 'level', 'unit_no', 'status', 'category', 'zone', 'created_at'];

        $callback = function () use ($columns) {
            $handle = fopen('php://output', 'w');
            // header row
            fputcsv($handle, $columns);

            Tenant::orderBy('trade_name')
                ->select(['customer_code', 'id', 'trade_name', 'location_type', 'level', 'unit_no', 'status', 'category', 'zone', 'created_at'])
                ->chunk(500, function ($rows) use ($handle) {
                    foreach ($rows as $r) {
                        fputcsv($handle, [
                            $r->customer_code,
                            $r->id,
                            $r->trade_name,
                            $r->location_type,
                            $r->level,
                            $r->unit_no,
                            $r->status,
                            $r->category,
                            $r->zone,
                            $r->created_at ? $r->created_at->format('Y-m-d H:i:s') : '',
                        ]);
                    }
                });

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Proxy endpoint for hourly transactions so the web UI can call a web-authenticated route
     * and we can adapt the single-date UI param to the API's date_from/date_to contract.
     */
    public function hourlyData(Request $request)
    {
        $request->validate([
            'date' => ['required', 'date'],
            'tenant_id' => ['nullable']
        ]);

        $date = $request->input('date');
        $tenantId = $request->input('tenant_id');

        // Use HourlyReportService (direct call) to avoid controller-to-controller calls
        $service = new HourlyReportService();
        $data = $service->getHourlyAggregates($date, $date, $tenantId, null, true);

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
            'tenant_id' => ['nullable']
        ]);

        $date = $request->input('date');
        $tenantId = $request->input('tenant_id');

        $service = new DailyReportService();
        $result = $service->getDailySummary($date, $tenantId, null, true);
        // Only return the aggregated daily summary to the web UI (no hourly breakdown)
        $result = ['summary' => $result['summary'] ?? ['gross_sales' => 0.0, 'net_sales' => 0.0, 'transaction_count' => 0, 'guest_count' => 0]];

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
