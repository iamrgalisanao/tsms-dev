<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\API\V1\TransactionController;
use App\Http\Controllers\API\V1\RetryHistoryController;
use App\Http\Controllers\API\V1\TerminalAuthController;
use App\Http\Controllers\TerminalTokenController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\API\V1\SubmissionEventController;
use App\Http\Controllers\API\V1\SubmissionEventItemsController;
use App\Http\Controllers\API\V1\SubmissionStatusController;
use App\Http\Controllers\API\V1\ProviderActivityMonitoringController;
use App\Http\Controllers\API\V1\IncidentController;
use App\Http\Controllers\API\V1\ChecksumSandboxController;
use App\Http\Controllers\API\V1\LicenseController;
use App\Http\Middleware\AttachCorrelationId;
use App\Http\Controllers\McpController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantUserController;

// MCP endpoint for kirschbaum-development/laravel-loop (public, no auth, CSRF-free)
Route::post('/mcp', [McpController::class, 'handle']);

// Authentication API Routes
Route::prefix('auth')->group(function () {
    Route::post('/login', [\App\Http\Controllers\API\Auth\AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [\App\Http\Controllers\API\Auth\AuthController::class, 'logout']);
        Route::get('/user', [\App\Http\Controllers\API\Auth\AuthController::class, 'user']);
    });
});

// License operations remain outside future license enforcement middleware so
// vendor-authorized accounts can inspect and recover invalid/restricted deployments.
Route::prefix('license')
    ->middleware(['auth:sanctum', 'throttle:30,1'])
    ->group(function () {
        Route::get('/status', [LicenseController::class, 'status'])
            ->middleware('license.vendor:view');
        Route::get('/capabilities', [LicenseController::class, 'capabilities'])
            ->middleware('license.vendor:view');
        Route::post('/upload', [LicenseController::class, 'upload'])
            ->middleware('license.vendor:upload');
        Route::post('/recovery-request', [LicenseController::class, 'recoveryRequest'])
            ->middleware('license.vendor:recovery_request');
    });

/*
|--------------------------------------------------------------------------

| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Dashboard API endpoints (for frontend dashboard) - secured with sanctum and roles
Route::middleware(['auth:sanctum'])->group(function () {
    
    // Admin/Manager/Commercial accessible management
    Route::middleware(['role:admin|manager|commercial'])->group(function () {
        Route::get('dashboard/system-health', [DashboardController::class, 'apiSystemHealth']);
        Route::get('dashboard/audit-logs', [DashboardController::class, 'apiAuditLogs']);
    });

    // Admin ONLY (Terminal Token Management)
    Route::middleware(['role:admin'])->group(function () {
        // Terminal Token Management
        Route::prefix('terminals/tokens')->group(function () {
            Route::get('/', [TerminalTokenController::class, 'apiIndex']);
            Route::get('/export', [TerminalTokenController::class, 'export']);
            Route::post('/{terminalId}/regenerate', [TerminalTokenController::class, 'apiRegenerate']);
            Route::post('/{terminalId}/revoke', [TerminalTokenController::class, 'apiRevoke']);
        });

        Route::post('terminals', [TerminalTokenController::class, 'apiStore']);
        Route::put('terminals/{terminal}', [TerminalTokenController::class, 'apiUpdateTerminal']);
        Route::put('terminals/{terminal}/expiry', [TerminalTokenController::class, 'updateExpiry']);
    });

    // Finance dashboards also consume notifications; include finance role.
    Route::middleware(['role:admin|manager|commercial|finance'])->group(function () {
        Route::get('dashboard/notifications', [DashboardController::class, 'apiNotifications']);
        Route::post('dashboard/notifications/dismiss', [DashboardController::class, 'apiDismissNotification']);
    });

    // Admin ONLY (Sensitive administration)
    Route::middleware(['role:admin'])->group(function () {
        // User Management API Routes
        Route::prefix('users')->group(function () {
            Route::get('/', [UserController::class, 'apiIndex']);
            Route::get('/roles', [UserController::class, 'apiRoles']);
            Route::post('/', [UserController::class, 'apiStore']);
            Route::put('/{user}', [UserController::class, 'apiUpdate']);
            Route::delete('/{user}', [UserController::class, 'apiDestroy']);
        });

        // Tenants API
        Route::post('tenants', [TenantController::class, 'store']);
        Route::get('tenants/export', [TenantController::class, 'export']);
        Route::get('tenants/{tenant}', [TenantController::class, 'show']);
        Route::put('tenants/{tenant}', [TenantController::class, 'update']);
        Route::delete('tenants/{tenant}', [TenantController::class, 'destroy']);

        // Tenant Users
        Route::get('tenants/{tenant}/users', [TenantUserController::class, 'index']);
        Route::post('tenants/{tenant}/users', [TenantUserController::class, 'store']);
        Route::delete('tenants/{tenant}/users/{user}', [TenantUserController::class, 'destroy']);

        Route::prefix('admin/corrections')->group(function () {
            Route::get('/tenants', [\App\Http\Controllers\Admin\TemporaryCorrectionController::class, 'tenants']);
            Route::post('/backup', [\App\Http\Controllers\Admin\TemporaryCorrectionController::class, 'backup']);
            Route::post('/apply', [\App\Http\Controllers\Admin\TemporaryCorrectionController::class, 'apply']);
        });
    });

    // Dashboard Data (Authorized roles)
    Route::middleware(['role:admin|manager|finance|commercial'])->group(function () {
        // Read-only access to tenants and terminals for filtering
        Route::get('tenants', [TenantController::class, 'index']);
        Route::get('terminals', function () {
            return \App\Models\PosTerminal::with('tenant:id,trade_name')
                ->get(['id', 'serial_number', 'tenant_id', 'machine_number']);
        });

        Route::get('dashboard/metrics', [DashboardController::class, 'apiMetrics']);
        Route::get('dashboard/charts', [DashboardController::class, 'apiCharts']);
        Route::get('dashboard/transactions', [DashboardController::class, 'apiTransactions']);
        Route::get('dashboard/terminal-performance', [DashboardController::class, 'apiTerminalPerformance']);
        Route::get('monitoring/activity/daily-report', [ProviderActivityMonitoringController::class, 'dailyReport'])
            ->middleware('role:admin|manager');
        Route::put('monitoring/tenants/{tenant}/config', [ProviderActivityMonitoringController::class, 'updateTenantConfig'])
            ->middleware('role:admin|manager');
        Route::put('monitoring/terminals/{terminal}/config', [ProviderActivityMonitoringController::class, 'updateTerminalConfig'])
            ->middleware('role:admin|manager');

        // Transaction Logs API endpoints
        Route::prefix('transactions/logs')->group(function () {
            Route::get('/', [\App\Http\Controllers\TransactionLogController::class, 'index']);
            Route::get('/summary', [\App\Http\Controllers\TransactionLogController::class, 'summary']);
            Route::get('/issues-count', [\App\Http\Controllers\TransactionLogController::class, 'issuesCount']);
            Route::get('/export', [\App\Http\Controllers\TransactionLogController::class, 'export']);
            Route::post('/reconcile', [\App\Http\Controllers\TransactionLogController::class, 'reconcile'])
                ->middleware('role:admin|finance|commercial');
            Route::get('/{id}', [\App\Http\Controllers\TransactionLogController::class, 'show']);
        });
    });
});

// Health check endpoint (public)
Route::get('/v1/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'version' => '1.0.0'
    ]);
});

// Terminal authentication (public - no middleware)
Route::prefix('v1/auth')->group(function () {
    Route::post('/terminal', [TerminalAuthController::class, 'authenticate'])
        ->middleware('throttle:pos-auth');
});

// V1 API Routes with Sanctum authentication
Route::prefix('v1')->middleware(['auth:sanctum', 'capture.terminal.ip', AttachCorrelationId::class])->group(function () {
    // Terminal management endpoints
    Route::post('/auth/refresh', [TerminalAuthController::class, 'refresh']);
    Route::get('/auth/me', [TerminalAuthController::class, 'me']);
    Route::post('/heartbeat', [TerminalAuthController::class, 'heartbeat'])
        ->middleware(['abilities:heartbeat:send', 'throttle:pos-heartbeat']);

    // Transaction endpoints with token abilities
    // POS ingestion is throttled per authenticated terminal/tenant, not just IP.
    Route::middleware(['abilities:transaction:create', 'throttle:pos-ingestion', 'license.valid'])->group(function () {
        // Legacy basic ingestion endpoint disabled (use /v1/transactions/official)
        // Route::post('/transactions', [TransactionController::class, 'store']);
        Route::post('/transactions/batch', [TransactionController::class, 'batchStore'])
            ->middleware(['ingestion.payload_size', 'ingestion.backpressure:processing', 'circuit.breaker:transaction-intake']);
        Route::post('/transactions/official', [TransactionController::class, 'storeOfficial'])
            ->middleware(['ingestion.payload_size', 'ingestion.backpressure:processing', 'circuit.breaker:transaction-intake']);
        Route::post('/transactions/{transaction_id}/refund', [TransactionController::class, 'refund']);
        Route::post('/transactions/{transaction_id}/void', [TransactionController::class, 'voidFromPOS']);
    });

    Route::middleware(['abilities:transaction:read', 'throttle:pos-read'])->group(function () {
        // Read-only provider testing/support lookup. This route does not mutate intake or processing state.
        Route::get('/submissions/{submission_uuid}', [SubmissionStatusController::class, 'show'])
            ->middleware('abilities:provider:testing');
        Route::get('/transactions/{transaction}/status', [TransactionController::class, 'status']);
        Route::get('/monitoring/tenants/activity', [ProviderActivityMonitoringController::class, 'tenants'])
            ->middleware('abilities:provider:testing');
        Route::get('/monitoring/terminals/activity', [ProviderActivityMonitoringController::class, 'terminals'])
            ->middleware('abilities:provider:testing');
        Route::get('/submission-events', [SubmissionEventController::class, 'index']);
        Route::get('/submission-events/{submission_uuid}/items', [SubmissionEventItemsController::class, 'index']);
        Route::get('/incidents', [IncidentController::class, 'index']);
        Route::get('/incidents/{id}', [IncidentController::class, 'show']);
    });

    // Terminal Token Management API (requires admin authentication)
    Route::middleware('abilities:admin:manage')->group(function () {
        Route::post('/terminals/{terminalId}/generate-token', [TerminalTokenController::class, 'generateToken']);
        Route::get('/terminals/{terminalId}/tokens', [TerminalTokenController::class, 'listTokens']);
        Route::post('/terminals/generate-all-tokens', [TerminalTokenController::class, 'generateTokensForAllTerminals']);

        // Dead-Letter Queue (DLQ) management — admin only
        Route::prefix('admin/failed-jobs')->group(function () {
            Route::get('/',             [\App\Http\Controllers\API\V1\FailedJobController::class, 'index']);
            Route::get('/stats',        [\App\Http\Controllers\API\V1\FailedJobController::class, 'stats']);
            Route::get('/{uuid}',       [\App\Http\Controllers\API\V1\FailedJobController::class, 'show']);
            Route::post('/retry-all',   [\App\Http\Controllers\API\V1\FailedJobController::class, 'retryAll']);
            Route::post('/{uuid}/retry',[\App\Http\Controllers\API\V1\FailedJobController::class, 'retry']);
            Route::delete('/{uuid}',    [\App\Http\Controllers\API\V1\FailedJobController::class, 'flush']);
        });

        // Observability Metrics
        Route::prefix('observability')->group(function () {
            Route::get('/intake',          [\App\Http\Controllers\API\V1\ObservabilityController::class, 'index']);
            Route::get('/intake/history',  [\App\Http\Controllers\API\V1\ObservabilityController::class, 'history']);
            Route::get('/intake/tenants',  [\App\Http\Controllers\API\V1\ObservabilityController::class, 'tenants']);
            Route::get('/intake/recent',   [\App\Http\Controllers\API\V1\ObservabilityController::class, 'recent']);
            Route::get('/intake/tenant-audit', [\App\Http\Controllers\API\V1\ObservabilityController::class, 'tenantIngestionAudit']);
            Route::get('/intake/duplicate-receipts', [\App\Http\Controllers\API\V1\ObservabilityController::class, 'duplicateReceipts']);
        });
    });

    // Token introspection (any authenticated token may introspect itself)
    Route::get('/tokens/introspect', [TerminalTokenController::class, 'introspectToken'])
        ->middleware('throttle:30,1');
});

// Public POS provider payload validator. This is rate-limited and diagnostic
// only; it does not persist or submit transactions.
Route::prefix('v1')->middleware(['throttle:30,1'])->group(function () {
    Route::post('/sandbox/payload/validate', [ChecksumSandboxController::class, 'validatePayload']);
});

// Checksum sandbox utility (tenant-authenticated; rate-limited)
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:30,1'])->group(function () {
    Route::post('/checksum/sandbox', [ChecksumSandboxController::class, 'compute']);
});

// Legacy V1 API Routes with rate limiting (for backward compatibility)
// Disabled by default. Enable temporarily via TSMS_ENABLE_LEGACY_API=true if you must
// support older POS clients while migrating to Sanctum-protected endpoints.
if (env('TSMS_ENABLE_LEGACY_API', false)) {
    Log::warning('Legacy API routes enabled (unauthenticated v1 endpoints)', [
        'flag' => 'TSMS_ENABLE_LEGACY_API',
    ]);

    Route::prefix('v1')->middleware(['rate.limit'])->group(function () {
        // Legacy basic ingestion endpoint disabled
        // Route::post('/transactions', [TransactionController::class, 'store']);
        Route::post('/transactions/batch', [TransactionController::class, 'batchStore']);
        Route::get('/transactions/{id}/status', [TransactionController::class, 'status']);
    });
}

// Public transaction endpoints for testing (legacy)
Route::middleware('api')->group(function () {
    // Legacy public ingestion endpoint disabled
    // Route::post('/transactions', [TransactionController::class, 'store']);
    Route::get('/transactions/{id}/status', [TransactionController::class, 'status']);
});

// V1 Retry History API Routes
Route::middleware(['auth:sanctum', 'role:admin|manager|finance|commercial'])
    ->prefix('v1')
    ->group(function () {
    // Now regular routes with parameters
    Route::get('/retry-history', [RetryHistoryController::class, 'index']);
    Route::post('/retry-history/{id}/retry', [RetryHistoryController::class, 'retry'])
        ->name('retry-history.retry');
    Route::get('/retry-history/{id}', [RetryHistoryController::class, 'show']);
    Route::get('/retry-history/{id}/status', [RetryHistoryController::class, 'status']);
});

// Include machine-to-machine read-only webapp API routes if present
if (file_exists(base_path('routes/webapp_api.php'))) {
    require base_path('routes/webapp_api.php');
}
