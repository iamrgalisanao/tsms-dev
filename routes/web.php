<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\CircuitBreakerController;
use App\Http\Controllers\TerminalTokenController;
use App\Http\Controllers\RetryHistoryController;
use App\Http\Controllers\LogViewerController;
use App\Http\Controllers\ProvidersController;

// Home route redirects based on auth status
Route::get('/', function () {
    if (Auth::check()) {
        return redirect('/dashboard');
    }
    return redirect('/login');
});

// Authentication Routes
Route::middleware('guest')->group(function () {
    Route::get('/login', function () {
        return view('auth.login');
    })->name('login');
    
    Route::post('/login', [LoginController::class, 'login']);
});

Route::middleware(['auth'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    
    // Dashboard route now includes POS providers content
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    
    // Terminal Tokens routes with proper regenerate route name
    Route::get('/terminal-tokens', [TerminalTokenController::class, 'index'])->name('terminal-tokens');
    Route::post('/terminal-tokens/{terminalId}/regenerate', [TerminalTokenController::class, 'regenerate'])->name('terminal-tokens.regenerate');
    
    // Circuit Breaker routes
    Route::get('/circuit-breakers', [CircuitBreakerController::class, 'index'])->name('circuit-breakers');
    Route::post('/circuit-breakers/{id}/reset', [CircuitBreakerController::class, 'reset'])->name('circuit-breakers.reset');
    
    // Retry History Routes
    Route::get('/dashboard/retry-history', [RetryHistoryController::class, 'index'])->name('dashboard.retry-history');
    Route::get('/retry-history/{id}', [RetryHistoryController::class, 'show'])->name('retry-history.show');
    Route::post('/retry-history/{id}/retry', [RetryHistoryController::class, 'retry'])->name('retry-history.retry');
    
    // Log Viewer Routes - Fix the route names
    Route::get('/logs', [LogViewerController::class, 'index'])->name('log-viewer');
    Route::get('/logs/{id}', [LogViewerController::class, 'show'])->name('log-viewer.show');
    Route::post('/logs/export', [LogViewerController::class, 'export'])->name('dashboard.log-viewer.export');
    
    // Fix the dashboard.log-viewer route
    Route::get('/dashboard/log-viewer', [LogViewerController::class, 'index'])->name('dashboard.log-viewer');
    
    // Add transactions route that was missing (causing the 404 error)
    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions');
    
    // POS Provider details page only (remove the index route)
    Route::get('/providers/{id}', [ProvidersController::class, 'show'])->name('dashboard.providers.show');
});