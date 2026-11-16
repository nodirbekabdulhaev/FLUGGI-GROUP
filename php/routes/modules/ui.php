<?php

use App\Http\Controllers\Ui\BoardColorsController;
use Illuminate\Support\Facades\Route;

// Интерфейс: цвета колонок Kanban (статусы SMM и задач проектов)
Route::get('/settings/board-colors', [BoardColorsController::class, 'index'])
    ->middleware('perm:settings.manage|reference.manage')->name('settings.boardColors');
Route::put('/settings/board-colors/{board}', [BoardColorsController::class, 'update'])->whereIn('board', ['smm', 'tasks'])
    ->middleware('perm:settings.manage|reference.manage,ALL')->name('settings.boardColors.update');
