<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\V1\TerminalAuthController;
use App\Http\Controllers\API\V1\TransactionController;
use App\Http\Controllers\API\V1\TransactionStatusController;

Route::post('/v1/register-terminal', [TerminalAuthController::class, 'register']);

Route::middleware(['api', 'auth:pos_api'])->group(function () {
    Route::post('/v1/transactions', [TransactionController::class, 'store']);
});
// routes/api.php


Route::middleware('auth:pos_api')->prefix('v1')->group(function () {
    Route::get('/transaction-status', [TransactionStatusController::class, 'poll']);
    Route::get('/transaction-status/{transaction_id}', [TransactionStatusController::class, 'show']);
    Route::get('/transaction-status/{transaction_id}/poll', [TransactionStatusController::class, 'poll']);
    Route::get('/transaction-status/poll', [TransactionStatusController::class, 'poll']);
});
