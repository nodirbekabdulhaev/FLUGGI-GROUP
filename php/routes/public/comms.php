<?php

use App\Http\Controllers\Automation\CronController;
use App\Http\Controllers\Integrations\MetaWebhookController;
use App\Http\Controllers\Integrations\PublicFormController;
use App\Http\Controllers\Integrations\WebsiteLeadController;
use App\Http\Controllers\Telegram\TelegramWebhookController;
use App\Http\Controllers\Vacancies\PostingController;
use App\Http\Middleware\GuestLocale;
use Illuminate\Support\Facades\Route;

// Публичные адреса модуля «Коммуникации»: вебхуки защищены своими секретами и лимитами запросов.
Route::post('/hooks/telegram', TelegramWebhookController::class)->middleware('throttle:120,1')->name('hooks.telegram');
Route::post('/hooks/cron', CronController::class)->middleware('throttle:10,1')->name('hooks.cron');
Route::get('/hooks/meta', [MetaWebhookController::class, 'verify'])->middleware('throttle:30,1')->name('hooks.meta.verify');
Route::post('/hooks/meta', [MetaWebhookController::class, 'handle'])->middleware('throttle:300,1')->name('hooks.meta');

// Форма заявки для сайта: страница (встраивается iframe через /embed.js) и приём заявок (в т.ч. от плагинов WordPress)
Route::get('/f/{key}', [PublicFormController::class, 'show'])->middleware([GuestLocale::class, 'throttle:60,1'])->name('forms.public');
Route::options('/hooks/intake/{key}', [PublicFormController::class, 'preflight'])->name('hooks.intake.preflight');
Route::post('/hooks/intake/{key}', [PublicFormController::class, 'submit'])->middleware([GuestLocale::class, 'throttle:10,1'])->name('hooks.intake');

// Заявки с сайта fluggi.uz: JSON с токеном Bearer (WEBSITE_LEADS_TOKEN), защита от дублей по external_id
Route::post('/hooks/website-leads', [WebsiteLeadController::class, 'store'])->middleware('throttle:120,1')->name('hooks.website-leads');

// Лента опубликованных вакансий для сайта fluggi.uz (открытые данные), создаётся в CRM: Команда → Вакансии → Вакансии компании
Route::get('/hooks/website-vacancies', [PostingController::class, 'feed'])->middleware('throttle:120,1')->name('hooks.website-vacancies');
