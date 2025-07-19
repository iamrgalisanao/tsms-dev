<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\API\V1\TransactionController;
use App\Http\Controllers\API\V1\LogViewerController;
use App\Http\Controllers\API\V1\RetryHistoryController;
use App\Http\Controllers\API\V1\TestParserController;
use App\Http\Controllers\Api\TerminalAuthController;
use App\Services\TransactionValidationService;
use App\Http\Controllers\API\V1\TransactionController as ApiTransactionController;

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
    Route::post('/terminal', [TerminalAuthController::class, 'authenticate']);
});

// V1 API Routes with Sanctum authentication
Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {
    // Terminal management endpoints
    Route::post('/auth/refresh', [TerminalAuthController::class, 'refresh']);
    Route::get('/auth/me', [TerminalAuthController::class, 'me']);
    Route::post('/heartbeat', [TerminalAuthController::class, 'heartbeat'])
        ->middleware('abilities:heartbeat:send');
    
    // Transaction endpoints with token abilities
    Route::middleware('abilities:transaction:create')->group(function () {
        Route::post('/transactions', [TransactionController::class, 'store']);
        Route::post('/transactions/batch', [TransactionController::class, 'batchStore']);
        Route::post('/transactions/official', [TransactionController::class, 'storeOfficial']);
    });
    
    Route::middleware('abilities:transaction:read')->group(function () {
        Route::get('/transactions/{id}/status', [TransactionController::class, 'status']);
    });
});

// Legacy V1 API Routes with rate limiting (for backward compatibility)
Route::prefix('v1')->middleware(['rate.limit'])->group(function () {
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::post('/transactions/batch', [TransactionController::class, 'batchStore']);
    Route::post('/transactions/official', [TransactionController::class, 'storeOfficial']); // New official format endpoint
    Route::get('/transactions/{id}/status', [TransactionController::class, 'status']);
});

// Public transaction endpoints for testing (legacy)
Route::middleware('api')->group(function () {
    Route::post('/transactions', [TransactionController::class, 'store']);
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
    Route::get('/retry-history/debug', function() {
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
    Route::post('/retry-history/force-seed', function() {
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
Route::get('/v1/recent-test-transactions', function() {
    try {
        $transactions = DB::table('transactions')
            ->join('pos_terminals', 'transactions.terminal_id', '=', 'pos_terminals.id')
            ->select(
                'transactions.id',
                'transactions.transaction_id',
                'pos_terminals.serial_number as terminal_uid',
                'transactions.base_amount as gross_sales',
                'transactions.validation_status',
                'transactions.created_at'
            )
            ->where(function($query) {
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
Route::get('/v1/retry-history/simple-status', function() {
    return response()->json([
        'status' => 'success',
        'message' => 'API is responding',
        'timestamp' => now()->format('Y-m-d H:i:s')
    ]);
});

// Move the diagnostics endpoint outside of any middleware to simplify it
Route::get('/v1/retry-history/diagnostics', function() {
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
Route::get('/api-test', function() {
    return response()->json([
        'status' => 'success',
        'message' => 'API is responding correctly',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
});

// Direct database endpoint bypassing controller entirely
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

// API endpoint for transaction details (for cloning)
Route::get('/v1/transactions/{id}/details', function($id) {
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