<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\V1\TransactionController;
use App\Http\Controllers\API\V1\TransactionStatusController;
use App\Http\Controllers\API\V1\TerminalAuthController;

// Public terminal registration endpoints
Route::post('register-terminal', [TerminalAuthController::class, 'register']);
Route::post('refresh-token', [TerminalAuthController::class, 'refresh']);

// Authenticated terminal endpoints
Route::middleware(['auth:pos_api'])->group(function () {
    Route::post('transactions', [TransactionController::class, 'store']);
    Route::get('transactions/{id}', [TransactionController::class, 'show']);
    Route::get('transaction-status/{id}', [TransactionStatusController::class, 'show']);
});
