<?php

use App\Http\Controllers\Smm\PublicationController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// Файл автопубликации для Meta (фото и обложка Reels передаются ссылкой): временная подписанная ссылка, без входа и сессии
$stateless = [StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class, EncryptCookies::class, AddQueuedCookiesToResponse::class];
Route::get('/hooks/ig-media/{id}/{kind}', [PublicationController::class, 'media'])
    ->whereUuid('id')->whereIn('kind', ['media', 'cover'])
    ->middleware(['signed', 'throttle:60,1'])->withoutMiddleware($stateless)->name('smm.media.public');
