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

        if ($isFinance) {
            return redirect('/finance');
        }

        return redirect('/dashboard');
    }

    return redirect('/login');
});

// Authentication Routes
Route::middleware('guest')->group(function () {
    Route::get('/login', function () {
        return view('app');
    })->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

// Public POS provider sandbox UI. The page is intentionally unauthenticated so
// providers can validate payloads before receiving production access.
Route::get('/sandbox/payload', function () {
    return view('app');
})->name('sandbox.payload');

// Public POS provider API documentation for testing/support-only endpoints.
Route::get('/docs/pos-provider/api-testing', function () {
    return view('app');
})->name('docs.pos-provider.api-testing');

Route::middleware(['auth'])->group(function () {
    // Main Dashboard Route (React SPA) - accessible to any authenticated user.
    // Role-based content/redirect is handled client-side by React Router.
    Route::get('/dashboard/{any?}', function () {
        return view('app');
    })->where('any', '.*')->name('dashboard');

    // Dashboard API sub-routes (admin/manager only)
    Route::prefix('dashboard')->name('dashboard.')->middleware('role:admin|manager')->group(function () {
        Route::post('/notifications/dismiss', [DashboardController::class, 'dismissNotification'])->name('notifications.dismiss');
        Route::get('/providers', [ProvidersController::class, 'index'])->name('providers.index');
        Route::get('/providers/{id}', [ProvidersController::class, 'show'])->name('providers.show');
        Route::get('/retry-history', [RetryHistoryController::class, 'index'])->name('retry-history');
        Route::get('/performance', [DashboardController::class, 'performance'])->name('performance');
        Route::post('/performance/export', [DashboardController::class, 'exportPerformance'])->name('performance.export');
    });

    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

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

        Route::get('/', function () {
            return view('app');
        })->name('index');

        // Transaction logs routes (UI serves React SPA)
        Route::prefix('logs')->name('logs.')->group(function () {
            Route::get('/', function () {
                return view('app');
            })->name('index');
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
    Route::prefix('terminal-tokens')->middleware('role:admin')->group(function () {
        Route::get('/', function () {
            return view('app');
        })->name('terminal-tokens');
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
    Route::get('/retry-check', function () {
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
    Route::get('/system-status', function () {
        return response()->json(['status' => 'online']);
    });

    Route::get('/monitoring/activity', function () {
        return view('app');
    })->name('monitoring.activity');

    // Keep terminal test route at the bottom
    Route::get('/terminal-test', function () {
        return view('app');
    })->middleware(['auth'])->name('terminal.test');

    // System Logs Route - serves React SPA or returns JSON for AJAX
    Route::get('/system-logs', [App\Http\Controllers\LogController::class, 'index'])->name('system-logs.index');

    // System Logs pruning UI (admin only)
    Route::middleware(['role:admin'])->group(function () {
        Route::get('/system-logs/prune', [\App\Http\Controllers\LogController::class, 'pruneForm'])->name('system-logs.prune.form');
        Route::post('/system-logs/prune', [\App\Http\Controllers\LogController::class, 'pruneExecute'])->name('system-logs.prune.exec');
        // Permanent/hard delete for a single system log (admin-only)
        Route::post('/system-logs/{id}/hard-delete', [\App\Http\Controllers\LogController::class, 'hardDelete'])
            ->name('system-logs.hard-delete');
        // Archived logs view and bulk actions (admin-only)
        Route::get('/system-logs/archived', [\App\Http\Controllers\LogController::class, 'archived'])
            ->name('system-logs.archived');
        Route::post('/system-logs/bulk-restore', [\App\Http\Controllers\LogController::class, 'bulkRestore'])
            ->name('system-logs.bulk-restore');
        Route::post('/system-logs/bulk-purge', [\App\Http\Controllers\LogController::class, 'bulkPurge'])
            ->name('system-logs.bulk-purge');
        Route::post('/system-logs/bulk-soft-delete', [\App\Http\Controllers\LogController::class, 'bulkSoftDelete'])
            ->name('system-logs.bulk-soft-delete');
    });

    // Finance Reports (web UI) - Protected strictly in React SPA and API
    Route::get('/reports', function () {
        return view('app');
    })->name('reports.index');

    Route::get('/finance', function () {
        return view('app');
    })->name('finance.dashboard');

    // CMSR API endpoints (admin, manager, finance, or commercial roles)
    Route::middleware(['role:admin|manager|finance|commercial'])->group(function () {
        // JSON API endpoint used by the reports dashboard (ajax)
        Route::get('/reports/data', [ReportsController::class, 'data'])->name('finance.reports');
        // Excel export endpoint
        Route::get('/finance/reports/export', [SalesReportExportController::class, 'export'])->name('finance.sales-report.export');
    });

    // Commercial — accessible to commercial and finance roles (UI shell)
    Route::prefix('commercial')->name('commercial.')->group(function () {
        // ── SPA page routes (React handles rendering & authorization) ─
        Route::get('/', function () { return view('app'); })->name('dashboard');
        Route::get('/reports', function () { return view('app'); })->name('reports');
        Route::get('/reports/hourly', function () { return view('app'); })->name('reports.hourly');
        Route::get('/reports/daily', function () { return view('app'); })->name('reports.daily');
        Route::get('/reports/weekly', function () { return view('app'); })->name('reports.weekly');
        Route::get('/reports/weekday', function () { return view('app'); })->name('reports.weekday');
        Route::get('/reports/weekend', function () { return view('app'); })->name('reports.weekend');
        Route::get('/reports/monthly', function () { return view('app'); })->name('reports.monthly');
        Route::get('/reports/yearly', function () { return view('app'); })->name('reports.yearly');
        Route::get('/tenants', function () { return view('app'); })->name('tenants');
        Route::get('/tenants/{id}', function () { return view('app'); })->name('tenants.show');
    });

    // Commercial & Finance API data access
    Route::middleware(['role:commercial|finance'])->group(function () {
        Route::prefix('commercial')->name('commercial.')->group(function () {
            // ── JSON/data API endpoints (controllers) ─────────────────
            Route::prefix('reports')->name('sales-report.')->group(function () {
                // Data proxy endpoints used by React pages via axios
                Route::get('/transactions/hourly', [CommercialReportsController::class, 'hourlyData'])->name('tsms-proxy.transactions.hourly');
                Route::get('/transactions/daily', [CommercialReportsController::class, 'dailyData'])->name('tsms-proxy.transactions.daily');
                Route::get('/transactions/weekly', [CommercialReportsController::class, 'weeklyData'])->name('tsms-proxy.transactions.weekly');
                Route::get('/transactions/monthly', [CommercialReportsController::class, 'monthlyData'])->name('tsms-proxy.transactions.monthly');
                Route::get('/transactions/yearly', [CommercialReportsController::class, 'yearlyData'])->name('tsms-proxy.transactions.yearly');
                Route::get('/transactions/weekday', [CommercialReportsController::class, 'weekdayData'])->name('tsms-proxy.transactions.weekday');
                Route::get('/transactions/weekend', [CommercialReportsController::class, 'weekendData'])->name('tsms-proxy.transactions.weekend');
                // Tenant list JSON (used by autocomplete dropdowns)
                Route::get('/tenants', [CommercialReportsController::class, 'tenants'])->name('tenants');
                
                // Export
                Route::get('/export', [CommercialReportsController::class, 'exportProxy'])->name('export');
            });

            // Tenant JSON detail (AJAX — returns JSON when Accept: application/json)
            Route::get('/reports/tenants/{id}', [CommercialReportsController::class, 'tenantShow'])->name('tenants.detail');
        });

        // Tenant export - admin/manager only
        Route::get('commercial/reports/tenants/export', [CommercialReportsController::class, 'tenantsExport'])
            ->name('commercial.sales-report.tenants.export')
            ->middleware('role:admin|manager');
    });

    // Logs export route
    Route::get('/logs/export/{format}', [App\Http\Controllers\LogExportController::class, 'export'])->name('logs.export');

    // User Management Routes - Protected strictly in React SPA and API
    Route::get('/users', function () {
        return view('app');
    })->name('users.index');
    Route::resource('users', UserController::class)->except(['index', 'show']);

    // Admin System Settings
    Route::middleware(['role:admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/settings', [\App\Http\Controllers\Admin\SystemSettingsController::class, 'edit'])->name('settings.edit');
        Route::post('/settings', [\App\Http\Controllers\Admin\SystemSettingsController::class, 'update'])->name('settings.update');
        // RBAC audit viewer
        Route::get('/rbac-audits', [\App\Http\Controllers\Admin\RbacAuditController::class, 'index'])->name('rbac-audits.index');
    });

    Route::fallback(function () {
        return view('app');
    });
});

// Email-based P2P approval links (no auth required; guarded by tokens)
