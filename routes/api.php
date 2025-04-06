<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\V1\TerminalAuthController;
use App\Http\Controllers\API\V1\TransactionController;

Route::post('/v1/register-terminal', [TerminalAuthController::class, 'register']);

Route::middleware(['api', 'auth:pos_api'])->group(function () {
    Route::post('/v1/transactions', [TransactionController::class, 'store']);
});
