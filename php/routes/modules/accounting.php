<?php

use App\Http\Controllers\Accounting\AccountsController;
use App\Http\Controllers\Accounting\BankImportController;
use App\Http\Controllers\Accounting\CategoriesController;
use App\Http\Controllers\Accounting\FiledReportsController;
use App\Http\Controllers\Accounting\LoansController;
use App\Http\Controllers\Accounting\OperationsController;
use App\Http\Controllers\Accounting\OverviewController;
use App\Http\Controllers\Accounting\PayrollController;
use App\Http\Controllers\Accounting\PeopleController;
use App\Http\Controllers\Accounting\RecategorizeController;
use App\Http\Controllers\Accounting\SalaryLinksController;
use App\Http\Controllers\Accounting\SettingsController;
use App\Http\Controllers\Accounting\StatementsController;
use App\Http\Controllers\Accounting\TaxesController;
use App\Http\Controllers\Accounting\TaxReportController;
use Illuminate\Support\Facades\Route;

// Бухгалтерия: счета, операции, свои статьи прихода и расхода, загрузка банковской выписки, оплаты из CRM, люди и зарплата для налоговой, налоги, отчёт в налоговую, настройки ставок
$read = 'perm:accounting.read';
$manage = 'perm:accounting.manage';
$export = [$read, 'perm:export.run'];

Route::prefix('/accounting')->name('accounting.')->group(function () use ($read, $manage, $export) {
    Route::get('/', [OverviewController::class, 'index'])->middleware($read)->name('overview');

    Route::get('/operations', [OperationsController::class, 'index'])->middleware($read)->name('operations');
    Route::get('/operations/export', [OperationsController::class, 'export'])->middleware($export)->name('operations.export');
    Route::post('/operations', [OperationsController::class, 'store'])->middleware($manage)->name('operations.store');
    Route::post('/operations/transfer', [OperationsController::class, 'transfer'])->middleware($manage)->name('operations.transfer');
    Route::patch('/operations/{id}/category', [OperationsController::class, 'recategorize'])->whereUuid('id')->middleware($manage)->name('operations.recategorize');
    Route::delete('/operations/{id}', [OperationsController::class, 'destroy'])->whereUuid('id')->middleware($manage)->name('operations.destroy');
    Route::post('/import-crm', [OperationsController::class, 'importCrm'])->middleware($manage)->name('import');

    Route::get('/bank-import', [BankImportController::class, 'show'])->middleware($manage)->name('bank');
    Route::post('/bank-import', [BankImportController::class, 'upload'])->middleware($manage)->name('bank.upload');
    Route::get('/bank-import/{token}', [BankImportController::class, 'preview'])->where('token', '[A-Za-z0-9]{40}')->middleware($manage)->name('bank.preview');
    Route::post('/bank-import/{token}/account', [BankImportController::class, 'account'])->where('token', '[A-Za-z0-9]{40}')->middleware($manage)->name('bank.account');
    Route::post('/bank-import/{token}/confirm', [BankImportController::class, 'confirm'])->where('token', '[A-Za-z0-9]{40}')->middleware($manage)->name('bank.confirm');
    Route::post('/bank-import/{token}/cancel', [BankImportController::class, 'cancel'])->where('token', '[A-Za-z0-9]{40}')->middleware($manage)->name('bank.cancel');
    Route::get('/bank-import/{token}/result', [BankImportController::class, 'result'])->where('token', '[A-Za-z0-9]{40}')->middleware($manage)->name('bank.result');

    Route::get('/accounts', [AccountsController::class, 'index'])->middleware($read)->name('accounts');
    Route::post('/accounts', [AccountsController::class, 'store'])->middleware($manage)->name('accounts.store');
    Route::put('/accounts/{id}', [AccountsController::class, 'update'])->whereUuid('id')->middleware($manage)->name('accounts.update');
    Route::post('/accounts/{id}/active', [AccountsController::class, 'active'])->whereUuid('id')->middleware($manage)->name('accounts.active');
    Route::delete('/accounts/{id}', [AccountsController::class, 'destroy'])->whereUuid('id')->middleware($manage)->name('accounts.destroy');

    Route::get('/categories', [CategoriesController::class, 'index'])->middleware($read)->name('categories');
    Route::post('/categories', [CategoriesController::class, 'store'])->middleware($manage)->name('categories.store');
    Route::put('/categories/{id}', [CategoriesController::class, 'update'])->whereUuid('id')->middleware($manage)->name('categories.update');
    Route::post('/categories/{id}/active', [CategoriesController::class, 'active'])->whereUuid('id')->middleware($manage)->name('categories.active');
    Route::delete('/categories/{id}', [CategoriesController::class, 'destroy'])->whereUuid('id')->middleware($manage)->name('categories.destroy');

    Route::get('/people', [PeopleController::class, 'index'])->middleware($read)->name('people');
    Route::post('/people', [PeopleController::class, 'store'])->middleware($manage)->name('people.store');
    Route::put('/people/{id}', [PeopleController::class, 'update'])->whereUuid('id')->middleware($manage)->name('people.update');
    Route::delete('/people/{id}', [PeopleController::class, 'destroy'])->whereUuid('id')->middleware($manage)->name('people.destroy');
    Route::delete('/people/{id}/salaries/{salaryId}', [PeopleController::class, 'destroySalary'])->whereUuid(['id', 'salaryId'])->middleware($manage)->name('people.salaries.destroy');

    Route::get('/payroll', [PayrollController::class, 'index'])->middleware($read)->name('payroll');
    Route::post('/payroll/calculate', [PayrollController::class, 'calculate'])->middleware($manage)->name('payroll.calculate');
    Route::patch('/payroll/{id}', [PayrollController::class, 'update'])->whereUuid('id')->middleware($manage)->name('payroll.update');
    Route::post('/payroll/approve', [PayrollController::class, 'approve'])->middleware($manage)->name('payroll.approve');
    Route::get('/payroll/link', [SalaryLinksController::class, 'index'])->middleware($manage)->name('payroll.link');
    Route::post('/payroll/link', [SalaryLinksController::class, 'apply'])->middleware($manage)->name('payroll.link.apply');
    Route::post('/payroll/unapprove', [PayrollController::class, 'unapprove'])->middleware($manage)->name('payroll.unapprove');

    Route::get('/taxes', [TaxesController::class, 'index'])->middleware($read)->name('taxes');
    Route::get('/taxes/export', [TaxesController::class, 'export'])->middleware($export)->name('taxes.export');
    // Отчёт в налоговую (приложение 4): смотреть — accounting.read, скачать файл — accounting.manage + export.run
    Route::get('/tax-report', [TaxReportController::class, 'index'])->middleware($read)->name('taxReport');
    Route::get('/tax-report/download', [TaxReportController::class, 'download'])->middleware([$manage, 'perm:export.run'])->name('taxReport.download');
    // Проверка статей: операции по получателям и распознанному типу платежа переносятся в верные статьи
    Route::get('/recategorize', [RecategorizeController::class, 'index'])->middleware($manage)->name('recategorize');
    Route::post('/recategorize', [RecategorizeController::class, 'apply'])->middleware($manage)->name('recategorize.apply');
    Route::get('/other-taxes', fn () => redirect('/accounting/recategorize?category=TAX_OTHER'))->middleware($manage);
    // Сданные отчёты: сальдо НДФЛ смотреть — accounting.read, загружать файлы — accounting.manage
    Route::get('/loans', [LoansController::class, 'index'])->middleware($read)->name('loans');
    Route::post('/loans', [LoansController::class, 'store'])->middleware($manage)->name('loans.store');
    Route::get('/loans/{id}', [LoansController::class, 'show'])->whereUuid('id')->middleware($read)->name('loans.show');
    Route::put('/loans/{id}', [LoansController::class, 'update'])->whereUuid('id')->middleware($manage)->name('loans.update');
    Route::delete('/loans/{id}', [LoansController::class, 'destroy'])->whereUuid('id')->middleware($manage)->name('loans.destroy');
    Route::post('/loans/{id}/schedule', [LoansController::class, 'schedule'])->whereUuid('id')->middleware($manage)->name('loans.schedule');
    Route::post('/loans/{id}/import', [LoansController::class, 'import'])->whereUuid('id')->middleware($manage)->name('loans.import');
    Route::post('/loans/{id}/match', [LoansController::class, 'match'])->whereUuid('id')->middleware($manage)->name('loans.match');
    Route::put('/loan-payments/{id}', [LoansController::class, 'updatePayment'])->whereUuid('id')->middleware($manage)->name('loans.payment.update');
    Route::delete('/loan-payments/{id}', [LoansController::class, 'deletePayment'])->whereUuid('id')->middleware($manage)->name('loans.payment.delete');
    Route::post('/loan-payments/{id}/paid', [LoansController::class, 'paid'])->whereUuid('id')->middleware($manage)->name('loans.payment.paid');
    Route::delete('/loan-payments/{id}/paid', [LoansController::class, 'unpaid'])->whereUuid('id')->middleware($manage)->name('loans.payment.unpaid');

    Route::get('/filed-reports', [FiledReportsController::class, 'index'])->middleware($read)->name('filed');
    Route::post('/filed-reports', [FiledReportsController::class, 'upload'])->middleware($manage)->name('filed.upload');
    Route::get('/filed-reports/{token}', [FiledReportsController::class, 'preview'])->where('token', '[A-Za-z0-9]{40}')->middleware($manage)->name('filed.preview');
    Route::post('/filed-reports/{token}/confirm', [FiledReportsController::class, 'confirm'])->where('token', '[A-Za-z0-9]{40}')->middleware($manage)->name('filed.confirm');
    Route::post('/filed-reports/{token}/cancel', [FiledReportsController::class, 'cancel'])->where('token', '[A-Za-z0-9]{40}')->middleware($manage)->name('filed.cancel');
    // Баланс и финрезультаты: смотреть — accounting.read, править и загружать сданные формы — accounting.manage
    Route::get('/statements', [StatementsController::class, 'index'])->middleware($read)->name('statements');
    Route::post('/statements/line', [StatementsController::class, 'line'])->middleware($manage)->name('statements.line');
    Route::post('/statements/import', [StatementsController::class, 'import'])->middleware($manage)->name('statements.import');
    Route::get('/annual-report', [TaxesController::class, 'annual'])->middleware($export)->name('annual');

    Route::get('/settings', [SettingsController::class, 'index'])->middleware($manage)->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->middleware($manage)->name('settings.update');
});
