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
    Route::middleware('throttle:circuit-breaker')->group(function() {
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
    });
});

// POS Terminal API Routes
Route::prefix('v1')->middleware(['auth:pos_api', 'throttle:api'])->group(function () {
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
});
