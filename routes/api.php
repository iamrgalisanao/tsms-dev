<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\V1\TerminalAuthController;
use App\Http\Controllers\API\V1\TransactionController;
use App\Http\Controllers\API\V1\TransactionStatusController;
use App\Http\Controllers\Admin\WebhookLogController;



use App\Http\Controllers\API\V1\SandboxTransactionController;


/**
 * Sandbox API Routes
 *
 * This route group is prefixed with 'sandbox' and applies the following middleware:
 * - 'auth:pos_api': Ensures the request is authenticated using the 'pos_api' guard.
 * - 'throttle:10,1': Limits requests to 10 per minute.
 *
 * Routes:
 * POST /sandbox/transaction
 *   - Controller: SandboxTransactionController@store
 *   - Name: sandbox.transaction.store
 *   - Description: Handles the creation of a new sandbox transaction.
 */
Route::prefix('sandbox')
    ->middleware(['auth:pos_api', 'throttle:10,1'])
    ->group(function () {
        Route::post('/transaction', [SandboxTransactionController::class, 'store'])
            ->name('sandbox.transaction.store');
    });


/**
 * Registers a new terminal.
 *
 * Endpoint: POST /v1/register-terminal
 * Controller: TerminalAuthController
 * Method: register
 *
 * Request Body:
 *   - Provide terminal registration details as required by the controller.
 *
 * Response:
 *   - Returns a JSON response indicating the result of the registration process.
 */
Route::post('/v1/register-terminal', [TerminalAuthController::class, 'register']);

/**
 * API Routes protected by 'api' and 'auth:pos_api' middleware.
 *
 * @group Transactions
 *
 * POST /v1/transactions
 * Handles the creation of new transactions via the TransactionController's store method.
 * 
 * Middleware:
 * - api: Applies API-specific middleware group.
 * - auth:pos_api: Ensures the request is authenticated using the 'pos_api' guard.
 */
Route::middleware(['api', 'auth:pos_api'])->group(function () {
    Route::post('/v1/transactions', [TransactionController::class, 'store']);
});



/**
 * API Routes for Transaction Status (Version 1)
 *
 * These routes are protected by the 'auth:pos_api' middleware and are prefixed with 'v1'.
 *
 * Endpoints:
 * - GET /v1/transaction-status
 *     Calls TransactionStatusController@poll to retrieve the status of transactions.
 *
 * - GET /v1/transaction-status/{transaction_id}
 *     Calls TransactionStatusController@show to retrieve the status of a specific transaction by its ID.
 *
 * - GET /v1/transaction-status/{transaction_id}/poll
 *     Calls TransactionStatusController@poll to poll the status of a specific transaction by its ID.
 *
 * - GET /v1/transaction-status/poll
 *     Calls TransactionStatusController@poll to poll the status of transactions.
 */
Route::middleware('auth:pos_api')->prefix('v1')->group(function () {
    Route::get('/transaction-status', [TransactionStatusController::class, 'poll']);
    Route::get('/transaction-status/{transaction_id}', [TransactionStatusController::class, 'show']);
    Route::get('/transaction-status/{transaction_id}/poll', [TransactionStatusController::class, 'poll']);
    Route::get('/transaction-status/poll', [TransactionStatusController::class, 'poll']);
});

Route::middleware('auth:pos_api')->prefix('v1')->group(function () {
    Route::get('/webhook-logs', [WebhookLogController::class, 'index']);
});

