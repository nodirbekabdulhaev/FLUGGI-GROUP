<?php

use App\Http\Controllers\Smm\IdeasController;
use App\Http\Controllers\Smm\VideoPlanController;
use Illuminate\Support\Facades\Route;

// Раздел «SMM» — собственный видеоконтент компании. Исполнителю без SMM-специальности — 403 (App\Services\Smm\SmmAccess).
$uuid = '[0-9a-fA-F-]{36}';

Route::get('/smm', [IdeasController::class, 'index'])->middleware('perm:smm.read')->name('smm.index');
Route::post('/smm', [IdeasController::class, 'store'])->middleware('perm:smm.create')->name('smm.store');
Route::get('/smm/{id}', [IdeasController::class, 'show'])->where('id', $uuid)->middleware('perm:smm.read')->name('smm.show');
Route::patch('/smm/{id}', [IdeasController::class, 'update'])->where('id', $uuid)->middleware('perm:smm.read')->name('smm.update');
Route::delete('/smm/{id}', [IdeasController::class, 'destroy'])->where('id', $uuid)->middleware('perm:smm.read')->name('smm.destroy');
Route::post('/smm/{id}/move', [IdeasController::class, 'move'])->where('id', $uuid)->middleware('perm:smm.read')->name('smm.move');
Route::post('/smm/{id}/approve', [IdeasController::class, 'approve'])->where('id', $uuid)->middleware('perm:smm.approve')->name('smm.approve');
Route::post('/smm/{id}/reject', [IdeasController::class, 'reject'])->where('id', $uuid)->middleware('perm:smm.approve')->name('smm.reject');
Route::post('/smm/{id}/comments', [IdeasController::class, 'comment'])->where('id', $uuid)->middleware('perm:smm.read')->name('smm.comment');

Route::put('/finance/payroll/video-plans', [VideoPlanController::class, 'update'])->middleware('perm:payroll.manage,ALL')->name('payroll.video-plans');
