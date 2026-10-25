<?php

use App\Http\Controllers\Smm\IdeasController;
use App\Http\Controllers\Smm\PublicationController;
use App\Http\Controllers\Smm\VideoPlanController;
use Illuminate\Support\Facades\Route;

// Раздел «SMM» — собственный видеоконтент компании. Исполнителю без SMM-специальности — 403 (App\Services\Smm\SmmAccess).
// Просмотр, перенос и комментарии открыты и видеографу идеи без права smm.read — проверка в SmmAccess::authorize().
$uuid = '[0-9a-fA-F-]{36}';

Route::get('/smm', [IdeasController::class, 'index'])->middleware('auth.only')->name('smm.index');
Route::post('/smm', [IdeasController::class, 'store'])->middleware('perm:smm.create')->name('smm.store');
Route::get('/smm/{id}', [IdeasController::class, 'show'])->where('id', $uuid)->middleware('auth.only')->name('smm.show');
Route::patch('/smm/{id}', [IdeasController::class, 'update'])->where('id', $uuid)->middleware('perm:smm.read')->name('smm.update');
Route::delete('/smm/{id}', [IdeasController::class, 'destroy'])->where('id', $uuid)->middleware('perm:smm.read')->name('smm.destroy');
Route::post('/smm/{id}/move', [IdeasController::class, 'move'])->where('id', $uuid)->middleware('auth.only')->name('smm.move');
Route::post('/smm/{id}/approve', [IdeasController::class, 'approve'])->where('id', $uuid)->middleware('perm:smm.approve')->name('smm.approve');
Route::post('/smm/{id}/reject', [IdeasController::class, 'reject'])->where('id', $uuid)->middleware('perm:smm.approve')->name('smm.reject');
Route::post('/smm/{id}/comments', [IdeasController::class, 'comment'])->where('id', $uuid)->middleware('auth.only')->name('smm.comment');

Route::put('/finance/payroll/video-plans', [VideoPlanController::class, 'update'])->middleware('perm:payroll.manage,ALL')->name('payroll.video-plans');

// Автопубликация (Instagram, Telegram-каналы): файл частями, подпись, места и расписание, повтор, отмена (права — как на редактирование идеи)
Route::middleware('perm:smm.read')->group(function () use ($uuid) {
    Route::post('/smm/{id}/publication/uploads', [PublicationController::class, 'start'])->where('id', $uuid)->name('smm.publication.upload');
    Route::post('/smm/{id}/publication/uploads/{upload}/chunk', [PublicationController::class, 'chunk'])->where(['id' => $uuid, 'upload' => $uuid])->name('smm.publication.chunk');
    Route::post('/smm/{id}/publication/uploads/{upload}/finish', [PublicationController::class, 'finish'])->where(['id' => $uuid, 'upload' => $uuid])->name('smm.publication.finish');
    Route::put('/smm/{id}/publication', [PublicationController::class, 'save'])->where('id', $uuid)->name('smm.publication.save');
    Route::post('/smm/{id}/publication/retry', [PublicationController::class, 'retry'])->where('id', $uuid)->name('smm.publication.retry');
    Route::post('/smm/{id}/publication/targets/{target}/retry', [PublicationController::class, 'retryTarget'])->where(['id' => $uuid, 'target' => $uuid])->name('smm.publication.target.retry');
    Route::delete('/smm/{id}/publication/telegram-file', [PublicationController::class, 'removeTelegramFile'])->where('id', $uuid)->name('smm.publication.telegram-file');
    Route::delete('/smm/{id}/publication', [PublicationController::class, 'cancel'])->where('id', $uuid)->name('smm.publication.cancel');
});
