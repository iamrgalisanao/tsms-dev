<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\V1\TerminalAuthController;
use App\Http\Controllers\API\V1\TransactionController;
use App\Http\Controllers\API\V1\TransactionStatusController;
use App\Http\Controllers\Admin\WebhookLogController;
use App\Http\Controllers\API\V1\DashboardController;
use App\Http\Controllers\Auth\AuthController;

// Move dashboard routes to the beginning before any API routes
Route::middleware(['web'])
    ->prefix('api/dashboard')
    ->group(function () {
        Route::get('/transactions', [DashboardController::class, 'transactions'])
            ->middleware('auth');
        Route::post('/transactions/{id}/retry', [DashboardController::class, 'retryTransaction'])
            ->middleware('auth');
    });

/**
 * POS Terminal API Routes (V1)
 */
Route::prefix('api/v1')->group(function () {
    // Terminal Authentication
    Route::post('register-terminal', [TerminalAuthController::class, 'register']);
    Route::post('refresh-token', [TerminalAuthController::class, 'refresh']);

    // Protected POS Terminal Routes
    Route::middleware(['auth:pos_api', 'throttle:10,1'])->group(function () {
        Route::post('transactions', [TransactionController::class, 'store']);
        Route::get('transactions/{id}', [TransactionController::class, 'show']);
        Route::get('transaction-status/{id}', [TransactionStatusController::class, 'show']);
    });
});

/**
 * Sandbox API Routes
 */
Route::prefix('api/sandbox')
    ->middleware(['auth:pos_api', 'throttle:10,1'])
    ->group(function () {
        Route::post('/transaction', [SandboxTransactionController::class, 'store'])
            ->name('sandbox.transaction.store');
    });

