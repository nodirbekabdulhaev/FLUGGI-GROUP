<?php

use App\Http\Controllers\Accounting\AccountsController;
use App\Http\Controllers\Accounting\OperationsController;
use App\Http\Controllers\Accounting\OverviewController;
use App\Http\Controllers\Accounting\PayrollController;
use App\Http\Controllers\Accounting\PeopleController;
use App\Http\Controllers\Accounting\SettingsController;
use App\Http\Controllers\Accounting\TaxesController;
use Illuminate\Support\Facades\Route;

// Бухгалтерия: счета, операции, оплаты из CRM, люди и зарплата для налоговой, налоги, настройки ставок
$read = 'perm:accounting.read';
$manage = 'perm:accounting.manage';
$export = [$read, 'perm:export.run'];

Route::prefix('/accounting')->name('accounting.')->group(function () use ($read, $manage, $export) {
    Route::get('/', [OverviewController::class, 'index'])->middleware($read)->name('overview');

    Route::get('/operations', [OperationsController::class, 'index'])->middleware($read)->name('operations');
    Route::get('/operations/export', [OperationsController::class, 'export'])->middleware($export)->name('operations.export');
    Route::post('/operations', [OperationsController::class, 'store'])->middleware($manage)->name('operations.store');
    Route::post('/operations/transfer', [OperationsController::class, 'transfer'])->middleware($manage)->name('operations.transfer');
    Route::delete('/operations/{id}', [OperationsController::class, 'destroy'])->whereUuid('id')->middleware($manage)->name('operations.destroy');
    Route::post('/import-crm', [OperationsController::class, 'importCrm'])->middleware($manage)->name('import');

    Route::get('/accounts', [AccountsController::class, 'index'])->middleware($read)->name('accounts');
    Route::post('/accounts', [AccountsController::class, 'store'])->middleware($manage)->name('accounts.store');
    Route::put('/accounts/{id}', [AccountsController::class, 'update'])->whereUuid('id')->middleware($manage)->name('accounts.update');
    Route::post('/accounts/{id}/active', [AccountsController::class, 'active'])->whereUuid('id')->middleware($manage)->name('accounts.active');
    Route::delete('/accounts/{id}', [AccountsController::class, 'destroy'])->whereUuid('id')->middleware($manage)->name('accounts.destroy');

    Route::get('/people', [PeopleController::class, 'index'])->middleware($read)->name('people');
    Route::post('/people', [PeopleController::class, 'store'])->middleware($manage)->name('people.store');
    Route::put('/people/{id}', [PeopleController::class, 'update'])->whereUuid('id')->middleware($manage)->name('people.update');
    Route::delete('/people/{id}', [PeopleController::class, 'destroy'])->whereUuid('id')->middleware($manage)->name('people.destroy');

    Route::get('/payroll', [PayrollController::class, 'index'])->middleware($read)->name('payroll');
    Route::post('/payroll/calculate', [PayrollController::class, 'calculate'])->middleware($manage)->name('payroll.calculate');
    Route::patch('/payroll/{id}', [PayrollController::class, 'update'])->whereUuid('id')->middleware($manage)->name('payroll.update');
    Route::post('/payroll/approve', [PayrollController::class, 'approve'])->middleware($manage)->name('payroll.approve');

    Route::get('/taxes', [TaxesController::class, 'index'])->middleware($read)->name('taxes');
    Route::get('/taxes/export', [TaxesController::class, 'export'])->middleware($export)->name('taxes.export');

    Route::get('/settings', [SettingsController::class, 'index'])->middleware($manage)->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->middleware($manage)->name('settings.update');
});
