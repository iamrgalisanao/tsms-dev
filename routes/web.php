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



// Home route redirects based on auth status
Route::get('/', function () {
    if (Auth::check()) {
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
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    
    // Main Dashboard Route
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    
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
        Route::get('/audit', [LogViewerController::class, 'auditTrail'])->name('audit');
        Route::get('/webhooks', [LogViewerController::class, 'webhookLogs'])->name('webhooks');
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
        
        // Transaction logs routes (admin/manager only)
        Route::middleware(['role:admin|manager'])->prefix('logs')->name('logs.')->group(function () {
            Route::get('/', [TransactionLogController::class, 'index'])->name('index');
            Route::get('/summary', [TransactionLogController::class, 'summary'])->name('summary');
            Route::get('/{id}', [TransactionLogController::class, 'show'])->name('show');
            Route::post('/export', [TransactionLogController::class, 'export'])->name('export');
            Route::get('/updates', [TransactionLogController::class, 'getUpdates'])->name('updates');
        });

        Route::get('/{id}', [TransactionController::class, 'show'])->name('show');
        Route::post('/{id}/retry', [TransactionController::class, 'retry'])
            ->middleware(['role:admin|manager'])
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

    // Logs export route
    Route::get('/logs/export/{format}', [App\Http\Controllers\LogExportController::class, 'export'])->name('logs.export');

    // User Management Routes - RBAC protected
    Route::middleware(['role:admin|manager'])->group(function () {
        Route::resource('users', UserController::class);
    });
});