<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\V1\TransactionController;
use App\Http\Controllers\API\V1\LogViewerController;
use App\Http\Controllers\API\V1\RetryHistoryController;
use App\Http\Controllers\API\V1\TestParserController;
use App\Services\TransactionValidationService;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// V1 API Routes
Route::prefix('v1')->group(function () {
    // Public health check endpoint (no auth required)
    Route::get('/healthcheck', function () {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'version' => config('app.version', '1.0.0'),
            'environment' => config('app.env')
        ]);
    });
    
    // For local/testing environment make the transactions endpoint public (no authentication)
    if (app()->environment('local', 'testing')) {
        // Transactions endpoint - no authentication for easier testing
        Route::post('/transactions', [TransactionController::class, 'store'])
            ->middleware(['transform.text']);
    } else {
        // Production environment uses JWT authentication
        Route::post('/transactions', [TransactionController::class, 'store'])
            ->middleware(['auth:api', 'transform.text']);
    }
    
    // Notifications endpoint for POS systems - still authentication required
    Route::get('/notifications', [TransactionController::class, 'getNotifications'])
        ->middleware('auth:api');

    // Parser test endpoint
    Route::post('parser-test', [TestParserController::class, 'testParser']);

    // Transaction status endpoint
    if (app()->environment('local', 'testing')) {
        // Remove auth middleware for testing
        Route::get('transactions/{transactionId}/status', [TransactionController::class, 'status']);
    } else {
        Route::get('transactions/{transactionId}/status', [TransactionController::class, 'status'])
            ->middleware('auth:api');
    }
});

// Test parser endpoint
Route::post('/v1/test-parser', function (Request $request) {
    $service = app(TransactionValidationService::class);
    $rawContent = $request->getContent();
    
    // Parse the raw content
    $result = $service->parseTextFormat($rawContent);
    
    return response()->json($result, 200, [], JSON_UNESCAPED_SLASHES);
})->middleware('api');

// Web API Routes for Internal Dashboard
Route::prefix('web')->group(function () {
    // Log Viewer API endpoints
    Route::get('dashboard/logs', [LogViewerController::class, 'index']);
    Route::get('dashboard/logs/{id}', [LogViewerController::class, 'show']);
    Route::get('dashboard/logs/export', [LogViewerController::class, 'export']);
    
    // Retry History API endpoints
    Route::get('dashboard/retry-history', [RetryHistoryController::class, 'index']);
    Route::get('dashboard/retry-history/{id}', [RetryHistoryController::class, 'show']);
    Route::post('dashboard/retry-history/{id}/retry', [RetryHistoryController::class, 'retrigger']);
});