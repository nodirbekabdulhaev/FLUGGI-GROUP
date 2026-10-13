<?php

use App\Http\Controllers\Import\ImportController;
use App\Services\Import\ImportService;
use Illuminate\Support\Facades\Route;

// Импорт из Excel/CSV (/import/{entity}): право — как на ручное создание записей раздела
$token = '[A-Za-z0-9]{40}';
foreach (ImportService::IMPORTERS as $entity => $importer) {
    $perm = 'perm:'.$importer::permission();
    Route::prefix('/import/'.$entity)->name('import.'.$entity)->middleware($perm)->group(function () use ($entity, $token) {
        Route::get('/', [ImportController::class, 'show'])->defaults('entity', $entity)->name('');
        Route::get('/template', [ImportController::class, 'template'])->defaults('entity', $entity)->name('.template');
        Route::post('/', [ImportController::class, 'upload'])->defaults('entity', $entity)->name('.upload');
        Route::get('/preview/{token}', [ImportController::class, 'preview'])->where('token', $token)->defaults('entity', $entity)->name('.preview');
        Route::post('/preview/{token}', [ImportController::class, 'confirm'])->where('token', $token)->defaults('entity', $entity)->name('.confirm');
        Route::delete('/preview/{token}', [ImportController::class, 'cancel'])->where('token', $token)->defaults('entity', $entity)->name('.cancel');
        Route::get('/result/{token}', [ImportController::class, 'result'])->where('token', $token)->defaults('entity', $entity)->name('.result');
    });
}
