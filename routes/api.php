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
Route::middleware('api')->group(function () {
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::get('/transactions/{id}/status', [TransactionController::class, 'status']);
});

// Public transaction endpoints for testing
Route::middleware('api')->group(function () {
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::get('/transactions/{id}/status', [TransactionController::class, 'status']);
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