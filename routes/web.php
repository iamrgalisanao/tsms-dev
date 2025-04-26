<?php

use Illuminate\Support\Facades\Route;

// Redirect root to login
Route::get('/', function () {
    return redirect()->route('login');
});

// Login routes
Route::get('/login', function () {
    return view('login');
})->name('login');

Route::post('/login', [\App\Http\Controllers\Auth\LoginController::class, 'login']);
Route::post('/logout', [\App\Http\Controllers\Auth\LoginController::class, 'logout'])->name('logout');

// Protected routes
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
});
