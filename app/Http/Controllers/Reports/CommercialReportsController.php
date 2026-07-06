<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Http\Controllers\Api\Webapp\HourlyTransactionsController as ApiHourlyController;
use App\Services\Reports\HourlyReportService;
use App\Services\Reports\DailyReportService;
use App\Services\Reports\FinanceCalculationService;
use App\Services\Reports\WeeklyReportService;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class CommercialReportsController extends Controller
{
    private function shouldAuditReportView(Request $request): bool
    {
        return $request->input('source') !== 'dashboard';
    }

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
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            // tenant_id may be omitted to request "All Tenants" aggregates from the service
            'tenant_id' => ['nullable']
        ]);

        $from = $request->input('date_from') ?: now()->subDays(6)->toDateString();
        $to = $request->input('date_to') ?: now()->toDateString();
        $tenantId = $request->input('tenant_id');

        $service = new WeeklyReportService();
        $result = $service->getWeeklySummary($from, $to, $tenantId, false, false, true);
        if (! $this->hasTenantScope($tenantId)) {
            $result['days'] = $this->tenantBreakdownRows($from, $to, 'date');
        }

        // Log result shape for debugging client 'No data' cases
        if (config('app.debug')) try {
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

        if ($this->shouldAuditReportView($request)) try {
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
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'tenant_id' => ['nullable']
        ]);

        $from = $request->input('date_from') ?: now()->subDays(6)->toDateString();
        $to = $request->input('date_to') ?: now()->toDateString();
        $tenantId = $request->input('tenant_id');

        $service = new WeeklyReportService();
        $result = $service->getWeeklySummary($from, $to, $tenantId, true, false, true);
        if (! $this->hasTenantScope($tenantId)) {
            $result['days'] = $this->tenantBreakdownRows($from, $to, 'date', true, false);
        }

        if ($this->shouldAuditReportView($request)) try {
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
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'tenant_id' => ['nullable']
        ]);

        $from = $request->input('date_from') ?: now()->subDays(6)->toDateString();
        $to = $request->input('date_to') ?: now()->toDateString();
        $tenantId = $request->input('tenant_id');

        $service = new WeeklyReportService();
        $result = $service->getWeeklySummary($from, $to, $tenantId, false, true, true);
        if (! $this->hasTenantScope($tenantId)) {
            $result['days'] = $this->tenantBreakdownRows($from, $to, 'date', false, true);
        }

        if ($this->shouldAuditReportView($request)) try {
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
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'month' => ['nullable', 'string'],
            'tenant_id' => ['nullable']
        ]);

        $from = $request->input('date_from');
        $to = $request->input('date_to');

        // Handle 'month' parameter from the Sales Report page UI (e.g. "2024-04")
        if ($request->has('month') && !$from) {
            try {
                $d = \Carbon\Carbon::parse($request->input('month'));
                $from = $d->copy()->startOfMonth()->toDateString();
                $to = $d->copy()->endOfMonth()->toDateString();
            } catch (\Throwable $e) {
                // fallback to current month on parse error
            }
        }

        // Final defaults
        $from = $from ?: now()->startOfMonth()->toDateString();
        $to = $to ?: now()->toDateString();
        $tenantId = $request->input('tenant_id');

        $service = new WeeklyReportService();
        $result = $service->getWeeklySummary($from, $to, $tenantId, false, false, true);
        if (! $this->hasTenantScope($tenantId)) {
            $result['days'] = $this->tenantBreakdownRows($from, $to, 'date');
        }

        if (config('app.debug')) try {
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

        if ($this->shouldAuditReportView($request)) try {
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
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'year' => ['nullable', 'string'],
            // allow empty tenant_id to request aggregates for all tenants
            'tenant_id' => ['nullable']
        ]);

        $from = $request->input('date_from');
        $to = $request->input('date_to');

        // Handle 'year' parameter from the Sales Report page UI (e.g. "2024")
        if ($request->has('year') && !$from) {
            try {
                $yStr = $request->input('year');
                $startOfYear = \Carbon\Carbon::createFromFormat('Y-m-d', "{$yStr}-01-01");
                $from = $startOfYear->copy()->startOfYear()->toDateString();
                $to = $startOfYear->copy()->endOfYear()->toDateString();
            } catch (\Throwable $e) {
                // catch parse error
            }
        }

        // Final defaults
        $from = $from ?: now()->startOfYear()->toDateString();
        $to = $to ?: now()->toDateString();
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

            if (! $this->hasTenantScope($tenantId)) {
                $months = $this->tenantBreakdownRows($from, $to, 'month');
            }

            foreach (['gross_sales', 'net_sales', 'vatable_sales', 'vat_exempt_sales', 'vat_amount', 'sc_pwd_discount', 'regular_discount', 'cash_payment', 'card_payment', 'other_tender'] as $k) {
                $summary[$k] = round($summary[$k], 2);
            }

            if ($this->shouldAuditReportView($request)) try {
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

            if (config('app.debug')) {
                Log::info('commercial.yearlyData result', [
                    'from' => $from,
                    'to' => $to,
                    'tenant' => $tenantId,
                    'summary' => $summary,
                    'months_count' => count($months),
                    'sample_months' => array_slice($months, 0, 3),
                ]);
            }

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
            ->get(['id', 'uuid', 'trade_name', 'customer_code', 'category', 'status', 'location', 'unit_no'])
            ->map(function ($t) {
                return [
                    'id' => $t->id,
                    'uuid' => $t->uuid,
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
        // Support finding by UUID (new) or ID (legacy)
        $tenant = Tenant::with(['posTerminals', 'company'])
            ->where('uuid', $id)
            ->orWhere('id', $id)
            ->firstOrFail();

        if ($request->wantsJson()) {
            try {
                // Calculate Current Month Sales
                $currentMonthStart = now()->startOfMonth();
                $currentMonthEnd = now()->endOfMonth();
                $monthSales = $tenant->posTerminals()->get()->map(function ($terminal) use ($currentMonthStart, $currentMonthEnd) {
                    try {
                        return $terminal->transactions()
                            ->whereBetween('transaction_timestamp', [$currentMonthStart, $currentMonthEnd])
                            ->where('validation_status', 'VALID')
                            ->sum('gross_sales');
                    } catch (\Throwable $e) {
                        return 0;
                    }
                })->sum();

                // Calculate YTD Sales
                $yearStart = now()->startOfYear();
                $yearEnd = now()->endOfYear();
                $ytdSales = $tenant->posTerminals()->get()->map(function ($terminal) use ($yearStart, $yearEnd) {
                    try {
                        return $terminal->transactions()
                            ->whereBetween('transaction_timestamp', [$yearStart, $yearEnd])
                            ->where('validation_status', 'VALID')
                            ->sum('gross_sales');
                    } catch (\Throwable $e) {
                        return 0;
                    }
                })->sum();

                // Fetch Recent Transactions
                $recentTransactions = \App\Models\Transaction::where('tenant_id', $id)
                    ->orderBy('transaction_timestamp', 'desc')
                    ->limit(10)
                    ->get()
                    ->map(function ($tx) {
                        return [
                            'id' => $tx->id,
                            'date' => $tx->transaction_timestamp ? $tx->transaction_timestamp->format('Y-m-d H:i') : 'N/A',
                            'reference_no' => $tx->receipt_no ?? $tx->transaction_id ?? 'N/A',
                            'amount' => (float) ($tx->gross_sales ?? 0),
                            'status' => $tx->validation_status ?? 'UNKNOWN',
                        ];
                    });

                // Determine active POS terminals for this tenant
                $activeTerminals = $tenant->posTerminals->filter(function ($terminal) {
                    try {
                        return $terminal->isActiveAndValid();
                    } catch (\Throwable $e) {
                        return false;
                    }
                })->map(function ($terminal) {
                    return [
                        'id' => $terminal->id,
                        'serial_number' => $terminal->serial_number,
                        'machine_number' => $terminal->machine_number,
                        'last_seen_at' => optional($terminal->last_seen_at)->toDateTimeString(),
                    ];
                })->values();

                // Prepare extra data for the profile
                $data = [
                    'id' => $tenant->id,
                    'trade_name' => $tenant->trade_name ?? 'N/A',
                    'customer_code' => $tenant->customer_code ?: ($tenant->company?->customer_code ?? 'N/A'),
                    'name' => $tenant->company?->company_name ?? 'N/A',
                    'level' => str_contains($tenant->location ?? '', 'Level') ? trim(str_replace('Level', '', $tenant->location ?? '')) : '1',
                    'unit_no' => $tenant->unit_no ?? 'N/A',
                    'category' => $tenant->category ?? 'Retail',
                    'ytd_sales' => round((float) $ytdSales, 2),
                    'month_sales' => round((float) $monthSales, 2),
                    'lease_expiry' => 'Dec 2026', // Mock for now as it's not in schema
                    'transactions' => $recentTransactions,
                    'active_terminals' => $activeTerminals,
                ];

                return response()->json($data);
            } catch (\Throwable $e) {
                Log::error('Error in tenantShow: ' . $e->getMessage(), ['tenant_id' => $id, 'trace' => $e->getTraceAsString()]);
                return response()->json(['error' => 'Failed to load tenant data: ' . $e->getMessage()], 500);
            }
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
            'date' => ['nullable', 'date'],
            'tenant_id' => ['nullable']
        ]);

        $date = $request->input('date') ?: now()->toDateString();
        $tenantId = $request->input('tenant_id');

        // Use HourlyReportService (direct call) to avoid controller-to-controller calls
        $service = new HourlyReportService();
        $data = $service->getHourlyAggregates($date, $date, $tenantId, null, true);
        if (! $this->hasTenantScope($tenantId)) {
            $data = $this->tenantBreakdownRows($date, $date, 'hour');
        }

        // Record a lightweight audit event so UI "Load Report" actions are visible in audit logs.
        if ($this->shouldAuditReportView($request)) try {
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
            'date' => ['nullable', 'date'],
            'tenant_id' => ['nullable']
        ]);

        $date = $request->input('date') ?: now()->toDateString();
        $tenantId = $request->input('tenant_id');

        $service = new DailyReportService();
        $result = $service->getDailySummary($date, $tenantId, null, true);
        // Include the hourly breakdown but rename the key to 'data' for UI compatibility
        $result = [
            'summary' => $result['summary'] ?? ['gross_sales' => 0.0, 'net_sales' => 0.0, 'transaction_count' => 0, 'guest_count' => 0],
            'data' => $result['hours'] ?? []
        ];
        if (! $this->hasTenantScope($tenantId)) {
            $result['data'] = $this->tenantBreakdownRows($date, $date, 'hour');
        }

        // Audit the UI action (non-blocking)
        if ($this->shouldAuditReportView($request)) try {
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
            'report_type' => ['nullable', 'in:hourly,daily,weekly,weekday,weekend,monthly,yearly'],
            'date' => ['nullable', 'date'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'month' => ['nullable', 'string'],
            'year' => ['nullable', 'string'],
            'tenant_id' => ['nullable'],
        ]);

        $type = $this->resolveExportType($request);
        [$periodLabel, $rows, $summary] = $this->buildCommercialExportData($request, $type);
        $tenantDetails = $this->exportTenantDetails($request->input('tenant_id'));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr(ucfirst($type), 0, 31));

        $title = ucwords(str_replace('_', ' ', "{$type} sales report"));
        $sheet->setCellValue('A1', $title);
        $sheet->setCellValue('A2', "Tenant Name: {$tenantDetails['name']}");
        $sheet->setCellValue('A3', "Customer Code: {$tenantDetails['customer_code']}");
        $sheet->setCellValue('A4', "Period: {$periodLabel}");
        $sheet->setCellValue('A5', 'Generated: ' . now()->format('Y-m-d H:i:s'));

        $headers = [
            'Tenant Name',
            'Customer Code',
            'Period',
            'Gross Sales',
            'Net Sales',
            'Vatable Sales',
            'VAT Amount',
            'VAT Exempt Sales',
            'SC/PWD Discount',
            'Regular Discount',
            'Cash Payment',
            'Card Payment',
            'Other Tender',
            'Transactions',
            'Guests',
        ];

        $headerRow = 7;
        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1) . $headerRow, $header);
        }

        $dataRow = $headerRow + 1;
        foreach ($rows as $row) {
            $values = [
                $row['tenant_name'] ?? $tenantDetails['name'],
                $row['customer_code'] ?? $tenantDetails['customer_code'],
                $row['period'] ?? '',
                (float) ($row['gross_sales'] ?? 0),
                (float) ($row['net_sales'] ?? 0),
                (float) ($row['vatable_sales'] ?? 0),
                (float) ($row['vat_amount'] ?? 0),
                (float) ($row['vat_exempt_sales'] ?? 0),
                (float) ($row['sc_pwd_discount'] ?? 0),
                (float) ($row['regular_discount'] ?? 0),
                (float) ($row['cash_payment'] ?? 0),
                (float) ($row['card_payment'] ?? 0),
                (float) ($row['other_tender'] ?? 0),
                (int) ($row['transaction_count'] ?? 0),
                (int) ($row['guest_count'] ?? 0),
            ];

            foreach ($values as $index => $value) {
                $typeHint = $index <= 2 ? DataType::TYPE_STRING : DataType::TYPE_NUMERIC;
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($index + 1) . $dataRow, $value, $typeHint);
            }
            $dataRow++;
        }

        $totalRow = $dataRow + 1;
        $sheet->setCellValue("A{$totalRow}", 'TOTAL');
        foreach (range(4, count($headers)) as $columnIndex) {
            $key = [
                4 => 'gross_sales',
                5 => 'net_sales',
                6 => 'vatable_sales',
                7 => 'vat_amount',
                8 => 'vat_exempt_sales',
                9 => 'sc_pwd_discount',
                10 => 'regular_discount',
                11 => 'cash_payment',
                12 => 'card_payment',
                13 => 'other_tender',
                14 => 'transaction_count',
                15 => 'guest_count',
            ][$columnIndex];

            $sheet->setCellValueExplicit(
                Coordinate::stringFromColumnIndex($columnIndex) . $totalRow,
                $summary[$key] ?? 0,
                DataType::TYPE_NUMERIC
            );
        }

        $lastColumn = $sheet->getHighestColumn();
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFEFF6FF');
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$totalRow}")
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);
        $firstDataRow = $headerRow + 1;
        $sheet->getStyle("D{$firstDataRow}:M{$totalRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        $sheet->getStyle("N{$firstDataRow}:O{$totalRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
        $sheet->getStyle("A{$totalRow}:{$lastColumn}{$totalRow}")->getFont()->setBold(true);
        $sheet->freezePane('A8');

        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        try {
            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'commercial_report_export',
                'action_type' => 'export',
                'resource_type' => 'report',
                'message' => "Exported {$type} commercial report",
                'metadata' => [
                    'report_type' => $type,
                    'period' => $periodLabel,
                    'tenant_id' => $request->input('tenant_id'),
                ],
                'logged_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write AuditLog for commercial report export: ' . $e->getMessage());
        }

        $safePeriod = preg_replace('/[^A-Za-z0-9-]+/', '-', $periodLabel) ?: now()->format('Y-m-d');
        $filename = "commercial-{$type}-sales-{$safePeriod}.xlsx";

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    private function resolveExportType(Request $request): string
    {
        if ($request->filled('report_type')) {
            return $request->input('report_type');
        }

        if ($request->filled('year')) {
            return 'yearly';
        }

        if ($request->filled('month')) {
            return 'monthly';
        }

        if ($request->filled('date_from') && $request->filled('date_to')) {
            return 'monthly';
        }

        return $request->filled('date') ? 'hourly' : 'daily';
    }

    private function buildCommercialExportData(Request $request, string $type): array
    {
        $tenantId = $request->input('tenant_id');

        return match ($type) {
            'hourly' => $this->hourlyExportData($request, $tenantId),
            'daily' => $this->dailyExportData($request, $tenantId),
            'weekly' => $this->weeklyExportData($request, $tenantId, false, false),
            'weekday' => $this->weeklyExportData($request, $tenantId, true, false),
            'weekend' => $this->weeklyExportData($request, $tenantId, false, true),
            'monthly' => $this->monthlyExportData($request, $tenantId),
            'yearly' => $this->yearlyExportData($request, $tenantId),
        };
    }

    private function hourlyExportData(Request $request, $tenantId): array
    {
        $date = $request->input('date') ?: now()->toDateString();
        if (! $this->hasTenantScope($tenantId)) {
            $rows = collect($this->tenantBreakdownRows($date, $date, 'hour'))
                ->map(fn ($row) => $this->normalizeExportRow($row, $row['period'] ?? $row['hour'] ?? ''))
                ->all();

            return [$date, $rows, $this->summarizeExportRows($rows)];
        }

        $data = (new HourlyReportService())->getHourlyAggregates($date, $date, $tenantId, null, true);
        $rows = collect($data)->map(fn ($row) => $this->normalizeExportRow($row, $row['hour'] ?? ''))->all();

        return [$date, $rows, $this->summarizeExportRows($rows)];
    }

    private function dailyExportData(Request $request, $tenantId): array
    {
        $date = $request->input('date') ?: now()->toDateString();
        if (! $this->hasTenantScope($tenantId)) {
            $rows = collect($this->tenantBreakdownRows($date, $date, 'hour'))
                ->map(fn ($row) => $this->normalizeExportRow($row, $row['period'] ?? $row['hour'] ?? ''))
                ->all();

            return [$date, $rows, $this->summarizeExportRows($rows)];
        }

        $result = (new DailyReportService())->getDailySummary($date, $tenantId, null, true);
        $rows = collect($result['hours'] ?? [])->map(fn ($row) => $this->normalizeExportRow($row, $row['hour'] ?? ''))->all();

        return [$date, $rows, $result['summary'] ?? $this->summarizeExportRows($rows)];
    }

    private function weeklyExportData(Request $request, $tenantId, bool $weekdayOnly, bool $weekendOnly): array
    {
        $from = $request->input('date_from') ?: now()->subDays(6)->toDateString();
        $to = $request->input('date_to') ?: now()->toDateString();
        if (! $this->hasTenantScope($tenantId)) {
            $rows = collect($this->tenantBreakdownRows($from, $to, 'date', $weekdayOnly, $weekendOnly))
                ->map(fn ($row) => $this->normalizeExportRow($row, $row['date'] ?? ''))
                ->all();

            return ["{$from}-to-{$to}", $rows, $this->summarizeExportRows($rows)];
        }

        $result = (new WeeklyReportService())->getWeeklySummary($from, $to, $tenantId, $weekdayOnly, $weekendOnly, true);
        $rows = collect($result['days'] ?? [])->map(fn ($row) => $this->normalizeExportRow($row, $row['date'] ?? ''))->all();

        return ["{$from}-to-{$to}", $rows, $result['summary'] ?? $this->summarizeExportRows($rows)];
    }

    private function monthlyExportData(Request $request, $tenantId): array
    {
        if ($request->filled('month')) {
            $month = \Carbon\Carbon::parse($request->input('month'));
            $from = $month->copy()->startOfMonth()->toDateString();
            $to = $month->copy()->endOfMonth()->toDateString();
        } else {
            $from = $request->input('date_from') ?: now()->startOfMonth()->toDateString();
            $to = $request->input('date_to') ?: now()->toDateString();
        }

        if (! $this->hasTenantScope($tenantId)) {
            $rows = collect($this->tenantBreakdownRows($from, $to, 'date'))
                ->map(fn ($row) => $this->normalizeExportRow($row, $row['date'] ?? ''))
                ->all();

            return ["{$from}-to-{$to}", $rows, $this->summarizeExportRows($rows)];
        }

        $result = (new WeeklyReportService())->getWeeklySummary($from, $to, $tenantId, false, false, true);
        $rows = collect($result['days'] ?? [])->map(fn ($row) => $this->normalizeExportRow($row, $row['date'] ?? ''))->all();

        return ["{$from}-to-{$to}", $rows, $result['summary'] ?? $this->summarizeExportRows($rows)];
    }

    private function yearlyExportData(Request $request, $tenantId): array
    {
        $year = $request->input('year') ?: now()->year;
        $from = $request->input('date_from') ?: "{$year}-01-01";
        $to = $request->input('date_to') ?: "{$year}-12-31";
        $start = \Carbon\Carbon::parse($from)->startOfMonth();
        $end = \Carbon\Carbon::parse($to)->startOfMonth();

        if (! $this->hasTenantScope($tenantId)) {
            $rows = collect($this->tenantBreakdownRows($from, $to, 'month'))
                ->map(fn ($row) => $this->normalizeExportRow($row, $row['month'] ?? ''))
                ->all();

            return ["{$from}-to-{$to}", $rows, $this->summarizeExportRows($rows)];
        }

        $rows = [];
        for ($date = $start->copy(); $date->lte($end); $date->addMonth()) {
            $monthFrom = $date->copy()->startOfMonth()->toDateString();
            $monthTo = $date->copy()->endOfMonth()->toDateString();
            $result = (new WeeklyReportService())->getWeeklySummary($monthFrom, $monthTo, $tenantId, false, false, true);
            $rows[] = $this->normalizeExportRow($result['summary'] ?? [], $date->format('Y-m'));
        }

        return ["{$from}-to-{$to}", $rows, $this->summarizeExportRows($rows)];
    }

    private function normalizeExportRow(array $row, string $period): array
    {
        return [
            'tenant_name' => $row['tenant_name'] ?? null,
            'customer_code' => $row['customer_code'] ?? null,
            'period' => $period,
            'gross_sales' => round((float) ($row['gross_sales'] ?? 0), 2),
            'net_sales' => round((float) ($row['net_sales'] ?? 0), 2),
            'vatable_sales' => round((float) ($row['vatable_sales'] ?? 0), 2),
            'vat_amount' => round((float) ($row['vat_amount'] ?? 0), 2),
            'vat_exempt_sales' => round((float) ($row['vat_exempt_sales'] ?? 0), 2),
            'sc_pwd_discount' => round((float) ($row['sc_pwd_discount'] ?? 0), 2),
            'regular_discount' => round((float) ($row['regular_discount'] ?? 0), 2),
            'cash_payment' => round((float) ($row['cash_payment'] ?? 0), 2),
            'card_payment' => round((float) ($row['card_payment'] ?? 0), 2),
            'other_tender' => round((float) ($row['other_tender'] ?? 0), 2),
            'transaction_count' => (int) ($row['transaction_count'] ?? 0),
            'guest_count' => (int) ($row['guest_count'] ?? 0),
        ];
    }

    private function summarizeExportRows(array $rows): array
    {
        $summary = [
            'gross_sales' => 0,
            'net_sales' => 0,
            'vatable_sales' => 0,
            'vat_amount' => 0,
            'vat_exempt_sales' => 0,
            'sc_pwd_discount' => 0,
            'regular_discount' => 0,
            'cash_payment' => 0,
            'card_payment' => 0,
            'other_tender' => 0,
            'transaction_count' => 0,
            'guest_count' => 0,
        ];

        foreach ($rows as $row) {
            foreach ($summary as $key => $value) {
                $summary[$key] += $row[$key] ?? 0;
            }
        }

        foreach (array_keys($summary) as $key) {
            if (! in_array($key, ['transaction_count', 'guest_count'], true)) {
                $summary[$key] = round((float) $summary[$key], 2);
            }
        }

        return $summary;
    }

    private function hasTenantScope($tenantId): bool
    {
        return filled($tenantId) && $tenantId !== 'all';
    }

    private function tenantBreakdownRows(
        string $from,
        string $to,
        string $bucket,
        bool $weekdayOnly = false,
        bool $weekendOnly = false
    ): array {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        $query = Transaction::query()
            ->with(['tenant:id,trade_name,customer_code', 'adjustments', 'taxes'])
            ->whereBetween('transaction_timestamp', [$start, $end]);

        if (config('tsms.reporting.exclude_voids_from_totals', true)) {
            $query->where('transaction_type', '!=', 'VOID')->whereNull('voided_at');
        }

        $finance = app(FinanceCalculationService::class);
        $rows = $query->get()
            ->filter(function (Transaction $transaction) use ($weekdayOnly, $weekendOnly) {
                $date = $this->reportCarbon($transaction);

                if ($weekdayOnly && $date->isWeekend()) {
                    return false;
                }

                if ($weekendOnly && ! $date->isWeekend()) {
                    return false;
                }

                return true;
            })
            ->groupBy(fn (Transaction $transaction) => implode('|', [
                $transaction->tenant_id ?? 'unassigned',
                $this->tenantBucket($transaction, $bucket),
            ]))
            ->map(function ($transactions) use ($finance, $bucket) {
                $first = $transactions->first();
                $tenant = $first?->tenant;
                $period = $this->tenantBucket($first, $bucket);
                $metrics = $finance->deriveMetrics(
                    $finance->aggregateComponents($transactions),
                    ['gross_sales_basis' => 'pre_deduction']
                );

                return [
                    'tenant_id' => $first?->tenant_id,
                    'tenant_name' => $tenant?->trade_name ?? 'Unassigned Tenant',
                    'customer_code' => $tenant?->customer_code ?? 'N/A',
                    'period' => $period,
                    'date' => $bucket === 'date' ? $period : null,
                    'day' => $bucket === 'date' ? $period : null,
                    'month' => $bucket === 'month' ? $period : null,
                    'hour' => $bucket === 'hour' ? substr($period, 11, 5) : null,
                    'gross_sales' => round((float) ($metrics['gross_sales'] ?? 0), 2),
                    'net_sales' => round((float) ($metrics['net_total'] ?? $metrics['net_sales'] ?? 0), 2),
                    'vatable_sales' => round((float) ($metrics['vatable_sales'] ?? 0), 2),
                    'vat_amount' => round((float) ($metrics['vat_amount'] ?? 0), 2),
                    'vat_exempt_sales' => round((float) ($metrics['sc_vat_exempt_sales'] ?? 0), 2),
                    'sc_pwd_discount' => round((float) ($metrics['senior_pwd'] ?? 0), 2),
                    'regular_discount' => round((float) (($metrics['regular_discount'] ?? 0) + ($metrics['total_promotions'] ?? 0)), 2),
                    'void' => 0.0,
                    'return' => 0.0,
                    'cash_payment' => 0.0,
                    'card_payment' => 0.0,
                    'other_tender' => 0.0,
                    'transaction_count' => $transactions->count(),
                    'guest_count' => 0,
                ];
            })
            ->sortBy([
                ['period', 'asc'],
                ['tenant_name', 'asc'],
            ])
            ->values()
            ->all();

        return $rows;
    }

    private function tenantBucket(?Transaction $transaction, string $bucket): string
    {
        $date = $this->reportCarbon($transaction);

        return match ($bucket) {
            'hour' => $date->format('Y-m-d H:00'),
            'month' => $date->format('Y-m'),
            default => $date->format('Y-m-d'),
        };
    }

    private function reportCarbon(?Transaction $transaction): Carbon
    {
        return Carbon::parse(
            $transaction?->transaction_timestamp
                ?? $transaction?->completed_at
                ?? $transaction?->created_at
                ?? now()
        )->timezone(config('tsms.transaction_logs.timezone', 'Asia/Manila') ?: 'Asia/Manila');
    }

    private function exportTenantDetails($tenantId): array
    {
        if (! $tenantId || $tenantId === 'all') {
            return [
                'name' => 'All Tenants',
                'customer_code' => 'All Customer Codes',
            ];
        }

        $tenant = Tenant::find($tenantId);

        return [
            'name' => $tenant?->trade_name ?? "Tenant #{$tenantId}",
            'customer_code' => $tenant?->customer_code ?: 'N/A',
        ];
    }
}
