<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\Webapp\TransactionController;
use App\Http\Controllers\API\Webapp\HourlyTransactionsController;
use App\Http\Controllers\API\Webapp\ReportsController;

/**
 * Webapp machine-to-machine read-only API
 *
 * Note: registration is gated by config('webapp_api.enabled'). When the
 * Webapp API is disabled we do not register these routes so that the
 * application effectively removes the machine-to-machine surface area.
 */
if (config('webapp_api.enabled', false)) {
    Route::prefix('v1/webapp')->middleware(['auth:sanctum', 'ensure.webapp.token', 'throttle:webapp'])->group(function () {
        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::get('/transactions/count', [TransactionController::class, 'count']);
        Route::get('/transactions/hourly', [HourlyTransactionsController::class, 'index']);
        Route::get('/transactions/{id}', [TransactionController::class, 'show']);

        Route::get('/reports/sales', [ReportsController::class, 'sales']);
        Route::get('/reports/sales/drilldown', [ReportsController::class, 'drilldown']);
        Route::get('/reports/summary', [ReportsController::class, 'summary']);
    });
}
