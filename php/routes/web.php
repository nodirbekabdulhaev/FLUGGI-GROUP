<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Публичные страницы (до входа)
Route::redirect('/', '/dashboard');
Route::get('/login', [AuthController::class, 'form'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:30,1');
Route::view('/offline', 'offline')->name('offline');
