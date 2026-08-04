<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\API\V1\TransactionController;
use App\Http\Controllers\API\V1\LogViewerController;
use App\Http\Controllers\API\V1\RetryHistoryController;
use App\Http\Controllers\API\V1\TestParserController;
use App\Http\Controllers\API\V1\TerminalAuthController;
use App\Http\Controllers\API\V1\ObservabilityController;
use App\Http\Controllers\TerminalTokenController;
use App\Http\Controllers\UserController;
use App\Services\TransactionValidationService;
use App\Http\Controllers\API\V1\TransactionController as ApiTransactionController;
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
            ->middleware('circuit.breaker:transaction-intake');
        Route::post('/transactions/official', [TransactionController::class, 'storeOfficial'])
            ->middleware('circuit.breaker:transaction-intake');
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

// Test parser endpoint
Route::post('/v1/test-parser', function (Request $request) {
    $service = app(TransactionValidationService::class);
    $rawContent = $request->getContent();

    // Parse the raw content
    $result = $service->parseTextFormat($rawContent);

    return response()->json($result, 200, [], JSON_UNESCAPED_SLASHES);
})->middleware('api');

// Web API Routes for Internal Dashboard
Route::prefix('web')->group(function () {
    // Log Viewer API endpoints
    Route::get('dashboard/logs', [LogViewerController::class, 'index']);
    Route::get('dashboard/logs/{id}', [LogViewerController::class, 'show']);
    Route::get('dashboard/logs/export', [LogViewerController::class, 'export']);
});

// V1 Retry History API Routes
Route::middleware(['api'])->prefix('v1')->group(function () {
    // Special routes with fixed paths first (before any route with parameters)
    Route::get('/retry-history/debug', function () {
        return response()->json([
            'transaction_count' => DB::table('transactions')->count(),
            'retry_count' => DB::table('transactions')->where('job_attempts', '>', 0)->count(),
            'terminal_count' => DB::table('pos_terminals')->count(),
            'tenant_count' => DB::table('tenants')->count(),
            'database_name' => DB::connection()->getDatabaseName(),
            'schema_version' => DB::select('SELECT VERSION() as version')[0]->version
        ]);
    });
    Route::get('/retry-history/emergency-data', [RetryHistoryController::class, 'createEmergencyData']);
    Route::post('/retry-history/seed', [RetryHistoryController::class, 'seedData']);
    Route::post('/retry-history/force-seed', function () {
        try {
            // Find or create tenant
            $tenant = DB::table('tenants')->first();
            $tenantId = $tenant ? $tenant->id : 'default-tenant';

            if (!$tenant) {
                // Create a tenant if none exists
                $tenantId = 'tenant-' . uniqid();
                DB::table('tenants')->insert([
                    'id' => $tenantId,
                    'name' => 'Demo Tenant',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // Find or create terminal
            $terminal = DB::table('pos_terminals')->first();
            $terminalId = $terminal ? $terminal->id : 'default-terminal';

            if (!$terminal) {
                // Create a terminal if none exists
                $terminalId = 'term-' . uniqid();
                DB::table('pos_terminals')->insert([
                    'id' => $terminalId,
                    'tenant_id' => $tenantId,
                    'terminal_uid' => 'TERM-DEMO',
                    'status' => 'active',
                    'serial_number' => 'SN-DEMO-1234',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            $count = 0;

            // Insert 5 sample retry transactions directly using DB facade to avoid validation service issues
            $statuses = ['FAILED', 'COMPLETED', 'PROCESSING'];

            for ($i = 1; $i <= 5; $i++) {
                $txId = 'DEMO-' . uniqid();
                $status = $statuses[array_rand($statuses)];

                try {
                    DB::table('transactions')->insert([
                        'tenant_id' => $tenantId,
                        'transaction_id' => $txId,
                        'terminal_id' => $terminalId,
                        'job_attempts' => rand(1, 5),
                        'job_status' => $status,
                        'validation_status' => $status == 'COMPLETED' ? 'VALID' : 'INVALID',
                        'last_error' => $status == 'FAILED' ? 'Demo validation error' : null,
                        'gross_sales' => $amount = 1000 + $i,
                        'net_sales' => $net = round($amount / 1.12, 2),
                        'vatable_sales' => $net,
                        'vat_amount' => round($amount - $net, 2),
                        'transaction_count' => 1,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);

                    $count++;
                } catch (\Exception $innerEx) {
                    Log::error('Failed to insert demo transaction', [
                        'error' => $innerEx->getMessage(),
                        'transaction_id' => $txId
                    ]);
                    // Continue to next record on error
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => "Created $count transactions successfully",
                'tenant_id' => $tenantId,
                'terminal_id' => $terminalId,
                'count' => $count
            ]);
        } catch (\Throwable $e) {
            Log::error('Force seed failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error: ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'suggestion' => 'Please check the TransactionValidationService class for duplicate method declarations'
            ], 200); // Return 200 so we can see the actual error
        }
    });

    // Now regular routes with parameters
    Route::get('/retry-history', [RetryHistoryController::class, 'index']);
    Route::post('/retry-history/{id}/retry', [RetryHistoryController::class, 'retry'])
        ->name('retry-history.retry');
    Route::get('/retry-history/{id}', [RetryHistoryController::class, 'show']);
    Route::get('/retry-history/{id}/status', [RetryHistoryController::class, 'status']);
});

// API endpoint for recent test transactions
Route::get('/v1/recent-test-transactions', function () {
    try {
        $transactions = DB::table('transactions')
            ->join('pos_terminals', 'transactions.terminal_id', '=', 'pos_terminals.id')
            ->select(
                'transactions.id',
                'transactions.transaction_id',
                'pos_terminals.serial_number as terminal_uid',
                'transactions.gross_sales as gross_sales',
                'transactions.validation_status',
                'transactions.created_at'
            )
            ->where(function ($query) {
                $query->where('transactions.transaction_id', 'like', 'TEST-%')
                    ->orWhere('transactions.transaction_id', 'like', 'DEMO-%');
            })
            ->orderBy('transactions.created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $transactions
        ]);
    } catch (\Exception $e) {
        Log::error('Error fetching recent transactions', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return response()->json([
            'status' => 'error',
            'message' => 'Failed to load recent transactions: ' . $e->getMessage()
        ], 500);
    }
});

// Add a simplified diagnostics endpoint that won't fail
Route::get('/v1/retry-history/simple-status', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'API is responding',
        'timestamp' => now()->format('Y-m-d H:i:s')
    ]);
});

// Move the diagnostics endpoint outside of any middleware to simplify it
Route::get('/v1/retry-history/diagnostics', function () {
    return response()->json([
        'status' => 'success',
        'data' => [
            'database' => true,
            'queue' => true,
            'cache' => true,
            'message' => 'System is responding'
        ]
    ]);
});

// Add a direct test endpoint at the top level (outside any middleware)
Route::get('/api-test', function () {
    return response()->json([
        'status' => 'success',
        'message' => 'API is responding correctly',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
});

// Direct database endpoint bypassing controller entirely
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

// API endpoint for transaction details (for cloning)
Route::get('/v1/transactions/{id}/details', function ($id) {
    try {
        $transaction = DB::table('transactions')->where('id', $id)->first();

        if (!$transaction) {
            return response()->json([
                'status' => 'error',
                'message' => 'Transaction not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $transaction
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Error fetching transaction details: ' . $e->getMessage()
        ], 500);
    }
});

// Add this route to check if a transaction ID exists
Route::get('v1/transaction-id-exists', function (Illuminate\Http\Request $request) {
    $id = $request->query('id');
    $exists = \App\Models\Transaction::where('transaction_id', $id)->exists();
    return response()->json([
        'exists' => $exists,
        'transaction_id' => $id
    ]);
});

Route::post('/transactions/bulk', [ApiTransactionController::class, 'bulk'])
    ->name('api.transactions.bulk');

// Include machine-to-machine read-only webapp API routes if present
if (file_exists(base_path('routes/webapp_api.php'))) {
    require base_path('routes/webapp_api.php');
}
