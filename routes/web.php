<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\CircuitBreakerController;
use App\Http\Controllers\TerminalTokenController;
use App\Http\Controllers\RetryHistoryController;

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
    
    // Dashboard Routes with fallback UI
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/transactions', [TransactionController::class, 'index'])->name('transactions');
    Route::get('/dashboard/circuit-breakers', [CircuitBreakerController::class, 'index'])->name('circuit-breakers');
    Route::get('/dashboard/terminal-tokens', [TerminalTokenController::class, 'index'])->name('terminal-tokens');
    
    // Retry History Routes
    Route::get('/dashboard/retry-history', [RetryHistoryController::class, 'index'])->name('dashboard.retry-history');
    Route::get('/dashboard/retry-history/{id}', [RetryHistoryController::class, 'show'])->name('dashboard.retry-history.show');
    Route::post('/dashboard/retry-history/{id}/retry', [RetryHistoryController::class, 'retry'])->name('dashboard.retry-history.retry');
    
    // Add direct route for token regeneration
    Route::post('/dashboard/terminal-tokens/{terminalId}/regenerate', [TerminalTokenController::class, 'regenerate'])
        ->name('regenerate-token');
});
