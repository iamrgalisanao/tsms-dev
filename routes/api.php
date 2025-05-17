<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\V1\TransactionController;
use App\Http\Controllers\API\V1\LogViewerController;
use App\Http\Controllers\API\V1\RetryHistoryController;

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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// V1 API Routes
Route::prefix('v1')->group(function () {
    // Transactions API endpoint - TSMS Core API
    Route::post('/transactions', [TransactionController::class, 'store'])
        ->middleware(['auth:api', 'transform.text']);
        
    // Healthcheck endpoint (no authentication required)
    Route::get('/healthcheck', function () {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'version' => config('app.version', '1.0.0'),
            'environment' => config('app.env')
        ]);
    });
    
    // Notifications endpoint for POS terminals
    Route::get('/notifications', [TransactionController::class, 'getNotifications'])
        ->middleware('auth:api');
});

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