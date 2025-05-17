<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\V1\TerminalAuthController;
use App\Http\Controllers\API\V1\TransactionController;
use App\Http\Controllers\API\V1\TransactionStatusController;
use App\Http\Controllers\API\V1\DashboardController;
use App\Http\Controllers\API\V1\RetryHistoryController;
use App\Http\Controllers\API\V1\CircuitBreakersController;
use App\Http\Controllers\API\V1\TerminalTokensController;
use App\Http\Controllers\API\V1\TestController;
use App\Http\Controllers\API\Auth\AuthController;
use App\Http\Controllers\API\SecurityDashboardController;
use App\Http\Controllers\API\SecurityReportController;
use App\Http\Controllers\CircuitBreakerController;
use App\Http\Controllers\TerminalTokenController;
use App\Http\Controllers\API\V1\LogViewerController;

// Authentication Routes
Route::prefix('auth')->group(function () {
    Route::middleware(\App\Http\Middleware\LoginRateLimiter::class)->group(function () {
        Route::post('/login', [AuthController::class, 'login']);
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/user', [AuthController::class, 'user']);
    });
});    

// Web Dashboard API Routes
Route::prefix('web')->middleware(['api'])->group(function () {
    // Publicly accessible circuit breaker state endpoint
    Route::get('/circuit-breaker/states', [CircuitBreakersController::class, 'getStates']);

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::middleware(['circuit-breaker'])->group(function() {
            Route::get('/dashboard/transactions', [DashboardController::class, 'transactions']);
            Route::post('/dashboard/transactions/{id}/retry', [DashboardController::class, 'retryTransaction']);
            
            // Retry History - Fix the endpoints to match what's being called in the browser
            Route::get('/dashboard/retry-history', [RetryHistoryController::class, 'index']);
            Route::get('/dashboard/retry-history/analytics', [RetryHistoryController::class, 'getAnalytics']);
            Route::get('/dashboard/retry-history/config', [RetryHistoryController::class, 'getRetryConfig']);
            Route::post('/dashboard/retry-history/{id}/retry', [RetryHistoryController::class, 'retrigger']);
            
            // Log Viewer
            Route::get('/dashboard/logs', [LogViewerController::class, 'index']);
            Route::get('/dashboard/logs/types', [LogViewerController::class, 'getLogTypes']);
            Route::get('/dashboard/logs/severities', [LogViewerController::class, 'getSeverities']);
            Route::get('/dashboard/logs/stream', [LogViewerController::class, 'streamLogs']);
            
            // Circuit Breakers
            Route::prefix('circuit-breaker')->group(function () {
                Route::get('/states', [CircuitBreakersController::class, 'getStates']);
                Route::get('/metrics', [CircuitBreakersController::class, 'getMetrics']);
            });
            Route::prefix('dashboard')->group(function () {
                Route::get('/circuit-breakers', [CircuitBreakersController::class, 'index']);
                Route::post('/circuit-breakers/{id}/reset', [CircuitBreakersController::class, 'reset']);
            });
            
            // Terminal Tokens
            Route::prefix('dashboard')->group(function () {
                Route::get('/terminal-tokens', [TerminalTokensController::class, 'index']);
                Route::post('/terminal-tokens/{terminalId}/regenerate', [TerminalTokensController::class, 'regenerate']);
            });
            
            // Security Reporting
            Route::prefix('security')->group(function () {
                // Dashboard endpoints
                Route::get('/dashboard', [App\Http\Controllers\API\SecurityDashboardController::class, 'index']);
                Route::get('/dashboard/events-summary', [App\Http\Controllers\API\SecurityDashboardController::class, 'eventsSummary']);
                Route::get('/dashboard/alerts-summary', [App\Http\Controllers\API\SecurityDashboardController::class, 'alertsSummary']);
                Route::get('/dashboard/time-series', [App\Http\Controllers\API\SecurityDashboardController::class, 'timeSeriesMetrics']);
                Route::get('/dashboard/advanced-visualization', [App\Http\Controllers\API\SecurityDashboardController::class, 'advancedVisualization']);
                
                // Reports endpoints
                Route::get('/reports', [SecurityReportController::class, 'index']);
                Route::post('/reports', [SecurityReportController::class, 'store']);
                Route::get('/reports/{id}', [SecurityReportController::class, 'show']);
                Route::get('/reports/{id}/export', [SecurityReportController::class, 'export']);
                
                // Report templates endpoints
                Route::get('/report-templates', [SecurityReportController::class, 'getTemplates']);
                Route::post('/report-templates', [SecurityReportController::class, 'storeTemplate']);
                Route::get('/report-templates/{id}', [SecurityReportController::class, 'getTemplate']);
                
                // Report scheduling endpoints
                Route::get('/reports/schedule', [App\Http\Controllers\API\SecurityReportController::class, 'getSchedules']);
                Route::post('/reports/schedule', [App\Http\Controllers\API\SecurityReportController::class, 'scheduleReport']);
                Route::put('/reports/schedule/{id}', [App\Http\Controllers\API\SecurityReportController::class, 'updateSchedule']);
                Route::delete('/reports/schedule/{id}', [App\Http\Controllers\API\SecurityReportController::class, 'deleteSchedule']);
            });
        });
    });
});

// Security Report Routes
Route::middleware(['api', 'auth:sanctum'])->group(function () {
    Route::prefix('security')->group(function () {
        Route::prefix('reports')->group(function () {
            Route::get('/', [SecurityReportController::class, 'index']);
            Route::post('/', [SecurityReportController::class, 'store']);
            Route::get('/{id}', [SecurityReportController::class, 'show']);
            Route::get('/{id}/export', [SecurityReportController::class, 'export']);
        });

        Route::prefix('report-templates')->group(function () {
            Route::get('/', [SecurityReportController::class, 'getTemplates']);
            Route::post('/', [SecurityReportController::class, 'storeTemplate']);
            Route::get('/{id}', [SecurityReportController::class, 'getTemplate']);
        });
    });
});

// POS Terminal API Routes
Route::prefix('v1')->middleware([
    \App\Http\Middleware\CircuitBreakerAuthBypass::class,
    'auth:pos_api',
    'throttle:api',
    'transform.text'  // Add text transformation middleware
])->group(function () {
    Route::post('transactions', [TransactionController::class, 'store']);
    Route::get('transactions/{id}', [TransactionController::class, 'show']);
    Route::get('transaction-status/{id}', [TransactionStatusController::class, 'show']);
});

// Public POS Terminal Routes
Route::prefix('v1')->group(function () {
    Route::post('register-terminal', [TerminalAuthController::class, 'register']);
    Route::post('refresh-token', [TerminalAuthController::class, 'refresh']);
});

// Test Endpoints (for circuit breaker verification)
Route::prefix('v1')->group(function () {
    Route::get('/test-circuit-breaker', [TestController::class, 'testCircuitBreaker'])
        ->middleware(['circuit-breaker:test_service'])
        ->withoutMiddleware(['auth:api', 'auth:sanctum', 'auth:pos_api']); // Temporarily disable auth for testing

    Route::get('/circuit-breakers/test-endpoint', [TestController::class, 'testEndpoint'])
        ->middleware(['circuit-breaker:test_service'])
        ->withoutMiddleware(['auth:api', 'auth:sanctum', 'auth:pos_api']);
        
    Route::post('/circuit-breakers/test-circuit', [TestController::class, 'testCircuit'])
        ->middleware(['circuit-breaker:test_service'])
        ->withoutMiddleware(['auth:api', 'auth:sanctum', 'auth:pos_api']);
        
    // Reset test service circuit breaker
    Route::post('/circuit-breakers/test-circuit/reset', [TestController::class, 'resetTestCircuit'])
        ->withoutMiddleware(['auth:api', 'auth:sanctum', 'auth:pos_api']);
        
    Route::get('terminal-tokens', [TerminalTokensController::class, 'index']);
    Route::get('terminal-tokens/{id}', [TerminalTokensController::class, 'show']);
    Route::post('terminal-tokens/verify', [TerminalTokensController::class, 'verify']);
    Route::post('terminal-tokens/{terminalId}/regenerate', [TerminalTokensController::class, 'regenerate']);
    Route::post('terminal-tokens/{id}/revoke', [TerminalTokensController::class, 'revoke']);
});

// Additional Terminal Tokens Route

Route::get('/test-password-hash', function () {
    $user = \App\Models\User::find(1);
    $plainPassword = 'password123';
    $result = [
        'user_exists' => $user ? true : false,
        'password_matches' => $user ? \Illuminate\Support\Facades\Hash::check($plainPassword, $user->password) : false,
        'hash' => $user ? $user->password : null,
        'email' => $user ? $user->email : null
    ];
    return response()->json($result);
})->withoutMiddleware(['auth:api', 'auth:sanctum', 'auth:pos_api']);

// Add this route at the end of your routes file

Route::post('/test-login', function (\Illuminate\Http\Request $request) {
    $credentials = $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);
    
    $user = \App\Models\User::where('email', $credentials['email'])->first();
    
    if (!$user || !\Illuminate\Support\Facades\Hash::check($credentials['password'], $user->password)) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid credentials'
        ], 401);
    }
    
    // Create token directly
    $token = $user->createToken('test-token')->plainTextToken;
    
    return response()->json([
        'success' => true,
        'token' => $token,
        'user' => $user
    ]);
})->withoutMiddleware(['auth:api', 'auth:sanctum', 'auth:pos_api']);

// API V1 Routes
Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {
    // Transaction Ingestion API (2.1.3.1)
    Route::post('/transactions', [TransactionController::class, 'store']);
});