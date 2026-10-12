<?php

use App\Http\Controllers\Crm\ClientController;
use App\Http\Controllers\Crm\CommentController;
use App\Http\Controllers\Crm\DealController;
use App\Http\Controllers\Crm\LeadController;
use App\Http\Controllers\Crm\MeetingController;
use App\Http\Controllers\Crm\PipelineController;
use App\Http\Controllers\Crm\ReferenceController;
use App\Http\Controllers\Crm\SalesController;
use App\Http\Controllers\Crm\TeamController;
use Illuminate\Support\Facades\Route;

// CRM: лиды, сделки, воронка, встречи, клиенты, комментарии, справочники и отделы (ТЗ §7–12, §65)
Route::name('crm.')->group(function () {
    Route::get('/sales', [SalesController::class, 'index'])->middleware('perm:lead.read|deal.read')->name('sales');

    // Лиды
    Route::get('/sales/leads', [LeadController::class, 'index'])->middleware('perm:lead.read')->name('leads.index');
    Route::post('/sales/leads', [LeadController::class, 'store'])->middleware('perm:lead.create')->name('leads.store');
    Route::get('/sales/leads/duplicates', [LeadController::class, 'duplicates'])->middleware('perm:lead.create|lead.update')->name('leads.duplicates');
    Route::prefix('/sales/leads/{id}')->whereUuid('id')->name('leads.')->group(function () {
        Route::get('/', [LeadController::class, 'show'])->middleware('perm:lead.read')->name('show');
        Route::put('/', [LeadController::class, 'update'])->middleware('perm:lead.update')->name('update');
        Route::delete('/', [LeadController::class, 'destroy'])->middleware('perm:lead.delete')->name('destroy');
        Route::post('/stage', [LeadController::class, 'stage'])->middleware('perm:lead.update')->name('stage');
        Route::post('/assign', [LeadController::class, 'assign'])->middleware('perm:lead.assign')->name('assign');
        Route::post('/close', [LeadController::class, 'close'])->middleware('perm:lead.update')->name('close');
        Route::post('/reopen', [LeadController::class, 'reopen'])->middleware('perm:lead.update')->name('reopen');
        Route::post('/convert', [LeadController::class, 'convert'])->middleware('perm:deal.create')->name('convert');
    });

    // Сделки
    Route::get('/sales/deals', [DealController::class, 'index'])->middleware('perm:deal.read')->name('deals.index');
    Route::post('/sales/deals', [DealController::class, 'store'])->middleware('perm:deal.create')->name('deals.store');
    Route::prefix('/sales/deals/{id}')->whereUuid('id')->name('deals.')->group(function () {
        Route::get('/', [DealController::class, 'show'])->middleware('perm:deal.read')->name('show');
        Route::put('/', [DealController::class, 'update'])->middleware('perm:deal.update')->name('update');
        Route::delete('/', [DealController::class, 'destroy'])->middleware('perm:deal.delete')->name('destroy');
        Route::post('/stage', [DealController::class, 'stage'])->middleware('perm:deal.change_stage')->name('stage');
        Route::post('/assign', [DealController::class, 'assign'])->middleware('perm:lead.assign')->name('assign');
        Route::post('/close', [DealController::class, 'close'])->middleware('perm:deal.update')->name('close');
        Route::post('/reopen', [DealController::class, 'reopen'])->middleware('perm:deal.update')->name('reopen');
    });

    // Воронка
    Route::get('/sales/pipeline', [PipelineController::class, 'show'])->middleware('perm:lead.read|deal.read')->name('pipeline');
    Route::get('/sales/pipeline/data', [PipelineController::class, 'data'])->middleware('perm:lead.read|deal.read')->name('pipeline.data');

    // Встречи
    Route::get('/sales/meetings', [MeetingController::class, 'index'])->middleware('perm:meeting.read')->name('meetings.index');
    Route::post('/sales/meetings', [MeetingController::class, 'store'])->middleware('perm:meeting.create')->name('meetings.store');
    Route::put('/sales/meetings/{id}', [MeetingController::class, 'update'])->whereUuid('id')->middleware('perm:meeting.update')->name('meetings.update');
    Route::post('/sales/meetings/{id}/complete', [MeetingController::class, 'complete'])->whereUuid('id')->middleware('perm:meeting.update')->name('meetings.complete');

    // Клиенты и контакты
    Route::get('/clients', [ClientController::class, 'index'])->middleware('perm:client.read')->name('clients.index');
    Route::post('/clients', [ClientController::class, 'store'])->middleware('perm:client.create')->name('clients.store');
    Route::get('/clients/{id}', [ClientController::class, 'show'])->whereUuid('id')->middleware('perm:client.read')->name('clients.show');
    Route::put('/clients/{id}', [ClientController::class, 'update'])->whereUuid('id')->middleware('perm:client.update')->name('clients.update');
    Route::delete('/clients/{id}', [ClientController::class, 'destroy'])->whereUuid('id')->middleware('perm:client.delete')->name('clients.destroy');
    Route::put('/clients/{id}/requisites', [ClientController::class, 'requisites'])->whereUuid('id')->middleware('perm:client.update')->name('clients.requisites');
    Route::post('/clients/{id}/contacts', [ClientController::class, 'storeContact'])->whereUuid('id')->middleware('perm:client.update')->name('contacts.store');
    Route::put('/contacts/{id}', [ClientController::class, 'updateContact'])->whereUuid('id')->middleware('perm:client.update')->name('contacts.update');
    Route::delete('/contacts/{id}', [ClientController::class, 'destroyContact'])->whereUuid('id')->middleware('perm:client.update')->name('contacts.destroy');

    // Комментарии (доступ — по записи)
    Route::post('/crm/comments', [CommentController::class, 'store'])->middleware('auth.only')->name('comments.store');
    Route::delete('/crm/comments/{id}', [CommentController::class, 'destroy'])->whereUuid('id')->middleware('auth.only')->name('comments.destroy');

    // Настройки → Справочники
    Route::get('/settings/references', [ReferenceController::class, 'index'])->middleware('perm:reference.manage')->name('references');
    Route::middleware('perm:reference.manage,ALL')->prefix('/settings/references')->name('references.')->group(function () {
        Route::post('/services', [ReferenceController::class, 'storeService'])->name('services.store');
        Route::put('/services/{id}', [ReferenceController::class, 'updateService'])->whereUuid('id')->name('services.update');
        Route::put('/stages/{id}', [ReferenceController::class, 'updateStage'])->whereUuid('id')->name('stages.update');
        Route::post('/directions', [ReferenceController::class, 'storeDirection'])->name('directions.store');
        Route::put('/directions/{id}', [ReferenceController::class, 'updateDirection'])->whereUuid('id')->name('directions.update');
        Route::post('/{kind}', [ReferenceController::class, 'storeItem'])->whereIn('kind', ['sources', 'loss-reasons'])->name('items.store');
        Route::put('/{kind}/{id}', [ReferenceController::class, 'updateItem'])->whereIn('kind', ['sources', 'loss-reasons'])->whereUuid('id')->name('items.update');
    });
    // Курс валюты влияет на финансы — только CEO (finance.company.read)
    Route::put('/settings/references/exchange-rate', [ReferenceController::class, 'rate'])->middleware('perm:finance.company.read,ALL')->name('references.rate');

    // Настройки → Отделы
    Route::get('/settings/teams', [TeamController::class, 'index'])->middleware('perm:employee.manage')->name('teams');
    Route::post('/settings/teams', [TeamController::class, 'store'])->middleware('perm:employee.manage,ALL')->name('teams.store');
    Route::put('/settings/teams/{id}', [TeamController::class, 'update'])->whereUuid('id')->middleware('perm:employee.manage,ALL')->name('teams.update');
    Route::delete('/settings/teams/{id}', [TeamController::class, 'destroy'])->whereUuid('id')->middleware('perm:employee.manage,ALL')->name('teams.destroy');
});
