<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\V1\TerminalAuthController;
use App\Http\Controllers\API\V1\TransactionController;
use App\Http\Controllers\API\V1\TransactionStatusController;
use App\Http\Controllers\API\V1\DashboardController;

// Dashboard Web API Routes - Using session auth
Route::group([
    'prefix' => 'web',
    'middleware' => ['auth:web'],
], function () {
    Route::get('/dashboard/transactions', [DashboardController::class, 'transactions']);
    Route::post('/dashboard/transactions/{id}/retry', [DashboardController::class, 'retryTransaction']);
});

// POS Terminal API Routes
Route::group([
    'prefix' => 'v1',
    'middleware' => ['auth:pos_api'],
], function () {
    Route::post('transactions', [TransactionController::class, 'store']);
    Route::get('transactions/{id}', [TransactionController::class, 'show']);
    Route::get('transaction-status/{id}', [TransactionStatusController::class, 'show']);
});

// Public POS Terminal Routes
Route::group([
    'prefix' => 'v1',
], function () {
    Route::post('register-terminal', [TerminalAuthController::class, 'register']);
    Route::post('refresh-token', [TerminalAuthController::class, 'refresh']);
});

