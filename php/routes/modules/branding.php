<?php

use App\Http\Controllers\Branding\BrandingController;
use Illuminate\Support\Facades\Route;

// Бренд: название проекта, логотип и цвет темы
Route::middleware('perm:settings.manage,ALL')->group(function () {
    Route::get('/settings/branding', [BrandingController::class, 'index'])->name('branding');
    Route::post('/settings/branding', [BrandingController::class, 'save'])->name('branding.save');
    Route::post('/settings/branding/reset', [BrandingController::class, 'reset'])->name('branding.reset');
});
