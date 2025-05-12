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
Route::prefix('web')->middleware(['api', 'auth:sanctum'])->group(function () {
    Route::middleware(['circuit-breaker'])->group(function() {
        Route::get('/dashboard/transactions', [DashboardController::class, 'transactions']);
        Route::post('/dashboard/transactions/{id}/retry', [DashboardController::class, 'retryTransaction']);
        
        // Retry History
        Route::get('/dashboard/retry-history', [RetryHistoryController::class, 'index']);
        
        // Circuit Breakers
        Route::prefix('circuit-breaker')->group(function () {
            Route::get('/states', [CircuitBreakersController::class, 'getStates']);
            Route::get('/metrics', [CircuitBreakersController::class, 'getMetrics']);
        });
        Route::get('/dashboard/circuit-breakers', [CircuitBreakersController::class, 'index']);
        Route::post('/dashboard/circuit-breakers/{id}/reset', [CircuitBreakersController::class, 'reset']);
        
        // Terminal Tokens
        Route::get('/dashboard/terminal-tokens', [TerminalTokensController::class, 'index']);
        Route::post('/dashboard/terminal-tokens/{terminalId}/regenerate', [TerminalTokensController::class, 'regenerate']);
        
        // Security Reporting
        Route::prefix('security')->group(function () {
            // Dashboard endpoints
            Route::get('/dashboard', [App\Http\Controllers\API\SecurityDashboardController::class, 'index']);
            Route::get('/dashboard/events-summary', [App\Http\Controllers\API\SecurityDashboardController::class, 'eventsSummary']);
            Route::get('/dashboard/alerts-summary', [App\Http\Controllers\API\SecurityDashboardController::class, 'alertsSummary']);
            Route::get('/dashboard/time-series', [App\Http\Controllers\API\SecurityDashboardController::class, 'timeSeriesMetrics']);
            Route::get('/dashboard/advanced-visualization', [App\Http\Controllers\API\SecurityDashboardController::class, 'advancedVisualization']);
            
            // Reports endpoints
            Route::get('/reports', [App\Http\Controllers\API\SecurityReportController::class, 'index']);
            Route::post('/reports', [App\Http\Controllers\API\SecurityReportController::class, 'store']);
            Route::get('/reports/{id}', [App\Http\Controllers\API\SecurityReportController::class, 'show']);
            Route::get('/reports/{id}/export', [App\Http\Controllers\API\SecurityReportController::class, 'export']);
            
            // Report scheduling endpoints
            Route::get('/reports/schedule', [App\Http\Controllers\API\SecurityReportController::class, 'getSchedules']);
            Route::post('/reports/schedule', [App\Http\Controllers\API\SecurityReportController::class, 'scheduleReport']);
            Route::put('/reports/schedule/{id}', [App\Http\Controllers\API\SecurityReportController::class, 'updateSchedule']);
            Route::delete('/reports/schedule/{id}', [App\Http\Controllers\API\SecurityReportController::class, 'deleteSchedule']);
        });
    });
});

// POS Terminal API Routes
Route::prefix('v1')->middleware([\App\Http\Middleware\CircuitBreakerAuthBypass::class, 'auth:pos_api', 'throttle:api'])->group(function () {
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
});