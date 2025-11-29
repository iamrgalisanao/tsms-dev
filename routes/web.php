<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\CircuitBreakerController;
use App\Http\Controllers\TerminalTokenController;
use App\Http\Controllers\RetryHistoryController;
use App\Http\Controllers\LogViewerController;
use App\Http\Controllers\ProvidersController;
use App\Http\Controllers\PosProvidersController;
use App\Http\Controllers\TransactionLogController;
use App\Http\Controllers\TestTransactionController;
use App\Http\Controllers\SystemLogController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\McpController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\Reports\CommercialReportsController;
use App\Http\Controllers\Finance\SalesReportExportController;



// Home route redirects based on auth status. Commercial-role users go to the
// commercial charts index so their primary landing page is the chart dashboard.
Route::get('/', function () {
    if (Auth::check()) {
        $user = Auth::user();
        // Role checks (spatie or legacy role attribute)
        $isCommercial = false;
        $isFinance = false;
        if (method_exists($user, 'hasRole')) {
            $isCommercial = $user->hasRole('commercial');
            $isFinance = $user->hasRole('finance');
        } else {
            $role = isset($user->role) ? strtolower($user->role) : '';
            $isCommercial = $role === 'commercial';
            $isFinance = $role === 'finance';
        }

        if ($isCommercial) {
            return redirect('/commercial');
        }

        // Finance users should land on the Transaction Logs page (admin logs view)
        // rather than the admin /dashboard. Transaction logs are accessible to
        // finance via the 'transactions/logs' routes (middleware: role:admin|manager|finance).
        if ($isFinance) {
            return redirect('/transactions/logs');
        }

        return redirect('/dashboard');
    }

    return redirect('/login');
});

// Authentication Routes
Route::middleware('guest')->group(function () {
    Route::get('/login', function () {
        return view('auth.login');
    })->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::middleware(['auth'])->group(function () {
    // Main Dashboard Route (admin-only)
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard')->middleware('role:admin');

    // Dashboard Group Routes
    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        // Dismiss admin notification (POST)
        Route::post('/notifications/dismiss', [DashboardController::class, 'dismissNotification'])->name('notifications.dismiss');
        Route::get('/providers', [ProvidersController::class, 'index'])->name('providers.index');
        Route::get('/providers/{id}', [ProvidersController::class, 'show'])->name('providers.show');
        Route::get('/retry-history', [RetryHistoryController::class, 'index'])->name('retry-history');
        Route::get('/performance', [DashboardController::class, 'performance'])->name('performance');
        Route::post('/performance/export', [DashboardController::class, 'exportPerformance'])->name('performance.export');
    });
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    
    // Main Dashboard Route (admin-only) - duplicate entry made explicit with admin middleware
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard')->middleware('role:admin');
    
    // Dashboard Group Routes
    Route::prefix('dashboard')->name('dashboard.')->group(function () {
        Route::get('/providers', [ProvidersController::class, 'index'])->name('providers.index');
        Route::get('/providers/{id}', [ProvidersController::class, 'show'])->name('providers.show');
        Route::get('/retry-history', [RetryHistoryController::class, 'index'])->name('retry-history');
        Route::get('/performance', [DashboardController::class, 'performance'])->name('performance');
        Route::post('/performance/export', [DashboardController::class, 'exportPerformance'])->name('performance.export');
    });

    // Centralized Log Viewer Routes - renamed from logs to log-viewer
    Route::prefix('log-viewer')->name('log-viewer.')->group(function () {
        Route::get('/', [LogViewerController::class, 'index'])->name('index');
        Route::get('/export/{format?}', [LogViewerController::class, 'export'])->name('export');
        Route::get('/context/{id}', [LogViewerController::class, 'getContext'])->name('context');
        Route::get('/audit-context/{id}', [LogViewerController::class, 'getAuditContext'])->name('audit-context');
    Route::get('/system-context/{id}', [LogViewerController::class, 'systemContext'])->name('system-context');
        Route::get('/filtered', [LogViewerController::class, 'getFilteredLogs'])->name('filtered');
        Route::get('/submission-events', [LogViewerController::class, 'submissionEventsData'])->name('submission.events');
        Route::get('/audit', [LogViewerController::class, 'auditTrail'])->name('audit');
        Route::get('/webhooks', [LogViewerController::class, 'webhookLogs'])->name('webhooks');
        // New: web route for submission event items (used by System Logs UI)
        Route::get('/submission-items/{submission_uuid}', [LogViewerController::class, 'submissionItems'])->name('submission-items');
    });

    // Keep test transaction routes before other transaction routes
    // Test Transaction Routes
    Route::get('/test-transaction', [TestTransactionController::class, 'index'])->name('test-transaction.index');
    Route::post('/test-transaction/process', [TestTransactionController::class, 'process'])->name('test-transaction.process');

    // Transaction Routes - Keep logs before other transaction routes
    Route::prefix('transactions')->name('transactions.')->group(function () {
        // Place specific routes first
        Route::get('/test', [TestTransactionController::class, 'index'])->name('test');
        Route::post('/test/process', [TestTransactionController::class, 'process'])->name('test.process');
        
        Route::get('/', [TransactionController::class, 'index'])->name('index');
        
    // Transaction logs routes (admin/manager/finance)
    Route::middleware(['role:admin|manager|finance'])->prefix('logs')->name('logs.')->group(function () {
            Route::get('/', [TransactionLogController::class, 'index'])->name('index');
            Route::get('/summary', [TransactionLogController::class, 'summary'])->name('summary');
            Route::get('/issues-count', [TransactionLogController::class, 'issuesCount'])->name('issues.count');
            Route::get('/{id}', [TransactionLogController::class, 'show'])->name('show');
            Route::post('/export', [TransactionLogController::class, 'export'])->name('export');
            Route::get('/updates', [TransactionLogController::class, 'getUpdates'])->name('updates');
        });

        Route::get('/{id}', [TransactionController::class, 'show'])->name('show');
        Route::post('/{id}/retry', [TransactionController::class, 'retry'])
            ->middleware(['role:admin|manager|finance'])
            ->name('retry');
    });

    // Bulk generate and retry routes
    Route::prefix('transactions')->group(function () {
        Route::post('/bulk-generate', [TransactionController::class, 'bulkGenerate'])->name('transactions.bulk-generate');
        Route::post('/retry/{transaction}', [TransactionController::class, 'retryTransaction'])->name('transactions.retry.process');
    });

    // Other Routes - Keep at root level
    Route::get('/circuit-breakers', [CircuitBreakerController::class, 'index'])->name('circuit-breakers');

    // Terminal Token Routes
    Route::prefix('terminal-tokens')->group(function () {
        Route::get('/', [TerminalTokenController::class, 'index'])->name('terminal-tokens');
        Route::post('/{terminalId}/regenerate', [TerminalTokenController::class, 'regenerate'])->name('terminal-tokens.regenerate');
        Route::post('/{terminalId}/revoke', [TerminalTokenController::class, 'revoke'])->name('terminal-tokens.revoke');
        Route::get('/{terminalId}/tokens', [TerminalTokenController::class, 'listTokens'])->name('terminal-tokens.list');
        Route::post('/generate-all', [TerminalTokenController::class, 'generateTokensForAllTerminals'])->name('terminal-tokens.generate-all');
    });

    // Provider Routes
    Route::prefix('providers')->name('providers.')->group(function () {
        Route::get('/', [PosProvidersController::class, 'index'])->name('index');
        Route::get('/{provider}', [PosProvidersController::class, 'show'])->name('show');
        Route::get('/stats', [PosProvidersController::class, 'statistics'])->name('stats');
        Route::post('/stats/generate', [PosProvidersController::class, 'generateStats'])->name('stats.generate');
    });

    // Direct database endpoint to diagnose retry history issues
    Route::get('/retry-check', function() {
        try {
            // Simple DB query with minimal dependencies
            $result = DB::select('SELECT COUNT(*) AS count FROM transactions WHERE job_attempts > 0');
            $count = $result[0]->count;
            
            return response()->json([
                'status' => 'success',
                'retry_count' => $count,
                'server_time' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 200); // Return 200 even on error to see the actual message
        }
    });

    // Very simple status endpoint with minimal code
    Route::get('/system-status', function() {
        return response()->json(['status' => 'online']);
    });

    // Keep terminal test route at the bottom
    Route::get('/terminal-test', function () {
        return view('app');
    })->middleware(['auth'])->name('terminal.test');

    // System Logs Route - renamed from logs to system-logs
    Route::get('/system-logs', [LogController::class, 'index'])->name('system-logs.index');

    // Finance Reports (web UI) - finance role only
    Route::middleware(['role:finance'])->group(function () {
        Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');
        // Finance dashboard URL: serve the finance dashboard blade (aggregated commercial charts)
        Route::get('/finance', function () {
            return view('reports.finance.dashboard');
        })->name('finance.dashboard');
        // JSON API endpoint used by the reports dashboard (ajax)
        Route::get('/reports/data', [ReportsController::class, 'data'])->name('finance.reports');
        // Excel export endpoint
        Route::get('/finance/reports/export', [SalesReportExportController::class, 'export'])->name('finance.sales-report.export');
    });

    // Commercial Reports - accessible to commercial users and finance users for cross-team reporting
    // Note: we intentionally allow 'finance' role here so finance users can view commercial dashboards/reports.
    // If you prefer to allow access to only specific routes, we can narrow this to a subset instead.
    Route::middleware(['role:commercial|finance'])->group(function () {
        Route::prefix('commercial')->name('commercial.')->group(function () {
            // Commercial dashboard root - show dedicated commercial dashboard (charts + tenant selector)
            Route::get('/', [CommercialReportsController::class, 'dashboard'])->name('dashboard');
            Route::prefix('reports')->name('sales-report.')->group(function () {
                Route::get('/hourly-sales-report', [CommercialReportsController::class, 'hourly'])->name('hourly');
                // Proxy endpoint used by the hourly report view to fetch hourly aggregates (adapts single-date UI param)
                Route::get('/transactions/hourly', [CommercialReportsController::class, 'hourlyData'])->name('tsms-proxy.transactions.hourly');
                // Proxy endpoint used by the daily report view to fetch daily summary
                Route::get('/transactions/daily', [CommercialReportsController::class, 'dailyData'])->name('tsms-proxy.transactions.daily');
                // Proxy endpoint used by the weekly report view to fetch per-day aggregates
                Route::get('/transactions/weekly', [CommercialReportsController::class, 'weeklyData'])->name('tsms-proxy.transactions.weekly');
                // Proxy endpoint used by the monthly report view to fetch per-day aggregates for a month
                Route::get('/transactions/monthly', [CommercialReportsController::class, 'monthlyData'])->name('tsms-proxy.transactions.monthly');
                // Proxy endpoint used by the yearly report view to fetch per-month aggregates for a year
                Route::get('/transactions/yearly', [CommercialReportsController::class, 'yearlyData'])->name('tsms-proxy.transactions.yearly');
                // Proxy endpoint used by the weekday report view to fetch per-day aggregates excluding weekends
                Route::get('/transactions/weekday', [CommercialReportsController::class, 'weekdayData'])->name('tsms-proxy.transactions.weekday');
                // Proxy endpoint used by the weekend report view to fetch per-day aggregates for weekends only
                Route::get('/transactions/weekend', [CommercialReportsController::class, 'weekendData'])->name('tsms-proxy.transactions.weekend');
                // Endpoint to fetch tenants for dropdown via AJAX
                Route::get('/tenants', [CommercialReportsController::class, 'tenants'])->name('tenants');
                // Export tenants CSV (web UI action)
                // Export should be more restricted — only admin or manager can export tenant CSVs.
                Route::get('/tenants/export', [CommercialReportsController::class, 'tenantsExport'])
                    ->name('tenants.export')
                    ->middleware('role:admin|manager');
                // Tenant details page (web UI). Keep this under the same 'tenants' prefix so
                // the sidebar link continues to work and ajax callers still receive JSON.
                Route::get('/tenants/{id}', [CommercialReportsController::class, 'tenantShow'])->name('tenants.show');
                // Export proxy: accept single-date & tenant_id from UI and adapt to finance export
                Route::get('/export', [CommercialReportsController::class, 'exportProxy'])->name('export');
                Route::get('/daily-sales-report', [CommercialReportsController::class, 'daily'])->name('daily');
                Route::get('/weekly-sales-report', [CommercialReportsController::class, 'weekly'])->name('weekly');
                Route::get('/weekday-sales-report', [CommercialReportsController::class, 'weekday'])->name('weekday');
                Route::get('/weekend-sales-report', [CommercialReportsController::class, 'weekend'])->name('weekend');
                Route::get('/monthly-sales-report', [CommercialReportsController::class, 'monthly'])->name('monthly');
                Route::get('/yearly-sales-report', [CommercialReportsController::class, 'yearly'])->name('yearly');
            });
        });
    });

    // Logs export route
    Route::get('/logs/export/{format}', [App\Http\Controllers\LogExportController::class, 'export'])->name('logs.export');

    // User Management Routes - RBAC protected
    Route::middleware(['role:admin|manager'])->group(function () {
        Route::resource('users', UserController::class);
    });

    // Admin System Settings
    Route::middleware(['role:admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/settings', [\App\Http\Controllers\Admin\SystemSettingsController::class, 'edit'])->name('settings.edit');
        Route::post('/settings', [\App\Http\Controllers\Admin\SystemSettingsController::class, 'update'])->name('settings.update');
        // RBAC audit viewer
        Route::get('/rbac-audits', [\App\Http\Controllers\Admin\RbacAuditController::class, 'index'])->name('rbac-audits.index');
    });
});