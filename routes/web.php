<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;

// Public routes
Route::get('/', function () {
    // Check if user is authenticated
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }
    // If not authenticated, redirect to login
    return redirect()->route('login');
});

Route::middleware(['guest'])->group(function () {
    Route::get('/login', function () {
        return view('login');
    })->name('login');

    Route::post('/login', [LoginController::class, 'login']);
});

Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// Protected routes
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    // Add API routes that should be protected by web auth
    Route::prefix('api/web')->group(function () {
        Route::get('/dashboard/transactions', [\App\Http\Controllers\API\V1\DashboardController::class, 'transactions']);
        Route::post('/dashboard/transactions/{id}/retry', [\App\Http\Controllers\API\V1\DashboardController::class, 'retryTransaction']);
    });
});
