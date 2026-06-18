<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Webapp\TransactionController;

/**
 * Webapp machine-to-machine read-only API
 *
 * Note: registration is gated by config('tsms.web_app.enabled'). When the
 * WebApp integration is disabled we do not register these routes so that the
 * application effectively removes the machine-to-machine surface area.
 */
if (config('tsms.web_app.enabled', false)) {
    Route::prefix('v1/webapp')->middleware(['auth:sanctum', \App\Http\Middleware\EnsureWebappToken::class])->group(function () {
        // Transactions: list, count, show
        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::get('/transactions/count', [TransactionController::class, 'count']);
        Route::get('/transactions/{id}', [TransactionController::class, 'show']);
    });
}
