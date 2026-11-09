<?php

use App\Http\Controllers\AtmController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;

if (config('cashsync.mode') === 'atm') {
    // ---------- ATM app (what customers use), on port 8000 ----------
    Route::get('/', [AtmController::class, 'home'])->name('home');
    Route::post('/login', [AtmController::class, 'login'])->name('login');
    Route::post('/logout', [AtmController::class, 'logout'])->name('logout');
    Route::get('/menu', [AtmController::class, 'menu'])->name('menu');
    Route::get('/balance', [AtmController::class, 'balance'])->name('balance');
    // The same form page serves three purposes; 'type' tells it which one
    Route::get('/withdraw', [AtmController::class, 'form'])->defaults('type', 'withdraw')->name('withdraw');
    Route::get('/deposit', [AtmController::class, 'form'])->defaults('type', 'deposit')->name('deposit');
    Route::get('/transfer', [AtmController::class, 'form'])->defaults('type', 'transfer')->name('transfer');
    // "Confirm" sends the form here; whereIn allows only these three words
    Route::post('/do/{type}', [AtmController::class, 'submit'])->whereIn('type', ['withdraw', 'deposit', 'transfer'])->name('submit');
    Route::get('/result', [AtmController::class, 'result'])->name('result');
} else {
    // ---------- Bank server (ports 8001 / 8002): the live dashboard ----------
    Route::redirect('/', '/dashboard');   // opening the bank's address goes straight to the dashboard
    Route::get('/dashboard', [DashboardController::class, 'show'])->name('dashboard');
}