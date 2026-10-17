<?php

use App\Http\Controllers\Branding\BrandingController;
use App\Support\Brand;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Бренд для браузера и PWA: иконки и манифест запрашиваются без сессии и входа.
// Адреса без расширения файла — их обрабатывает Laravel, а не раздача статики хостинга.
$stateless = [StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class, EncryptCookies::class, AddQueuedCookiesToResponse::class];
Route::get('/brand/{file}', [BrandingController::class, 'file'])->whereIn('file', array_keys(Brand::FILES))
    ->withoutMiddleware($stateless)->name('brand.file');
Route::get('/app-manifest', [BrandingController::class, 'manifest'])->withoutMiddleware($stateless)->name('brand.manifest');
