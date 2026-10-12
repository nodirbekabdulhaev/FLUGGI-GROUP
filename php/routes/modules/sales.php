<?php

use App\Http\Controllers\Sales\CommissionController;
use App\Http\Controllers\Sales\ContractController;
use App\Http\Controllers\Sales\DocumentController;
use App\Http\Controllers\Sales\FileController;
use App\Http\Controllers\Sales\Middleware\Idempotent;
use App\Http\Controllers\Sales\PaymentController;
use App\Http\Controllers\Sales\ProposalController;
use App\Http\Controllers\Sales\TariffController;
use Illuminate\Support\Facades\Route;

// Продажи: КП, договоры, оплаты, комиссии, документы, файлы, тарифы (ТЗ §15–19, §32–34, §46)
Route::name('sales.')->group(function () {
    // КП
    Route::get('/sales/proposals', [ProposalController::class, 'index'])->middleware('perm:proposal.read')->name('proposals.index');
    Route::post('/sales/proposals', [ProposalController::class, 'store'])->middleware(['perm:proposal.create', Idempotent::class])->name('proposals.store');
    Route::prefix('/sales/proposals/{id}')->whereUuid('id')->name('proposals.')->group(function () {
        Route::put('/', [ProposalController::class, 'update'])->middleware('perm:proposal.update')->name('update');
        Route::get('/versions', [ProposalController::class, 'versions'])->middleware('perm:proposal.read')->name('versions');
        Route::post('/{action}', [ProposalController::class, 'action'])
            ->whereIn('action', ['submit-approval', 'approve', 'send', 'mark-viewed', 'accept', 'reject'])
            ->middleware('perm:proposal.update|proposal.send|proposal.approve')->name('action');
    });

    // Договоры
    Route::get('/sales/contracts', [ContractController::class, 'index'])->middleware('perm:contract.read')->name('contracts.index');
    Route::post('/sales/contracts', [ContractController::class, 'store'])->middleware(['perm:contract.create', Idempotent::class])->name('contracts.store');
    Route::patch('/sales/contracts/{id}', [ContractController::class, 'update'])->whereUuid('id')->middleware('perm:contract.update')->name('contracts.update');
    Route::post('/sales/contracts/{id}/{action}', [ContractController::class, 'action'])->whereUuid('id')
        ->whereIn('action', ['send', 'submit-approval', 'sign', 'cancel'])->middleware('perm:contract.update')->name('contracts.action');

    // Документы КП и договора
    Route::get('/documents/{kind}/{id}', [DocumentController::class, 'show'])->whereIn('kind', ['proposals', 'contracts'])->whereUuid('id')
        ->middleware('perm:proposal.read|contract.read')->name('documents.show');
    Route::get('/documents/{kind}/{id}/check', [DocumentController::class, 'check'])->whereIn('kind', ['proposals', 'contracts'])->whereUuid('id')
        ->middleware('perm:proposal.read|contract.read')->name('documents.check');

    // Оплаты
    Route::get('/finance/payments', [PaymentController::class, 'index'])->middleware('perm:payment.read')->name('payments.index');
    Route::post('/finance/payments', [PaymentController::class, 'store'])->middleware(['perm:payment.create', Idempotent::class])->name('payments.store');
    Route::prefix('/finance/payments/{id}')->whereUuid('id')->name('payments.')->group(function () {
        Route::patch('/', [PaymentController::class, 'update'])->middleware('perm:payment.create')->name('update');
        Route::post('/confirm', [PaymentController::class, 'confirm'])->middleware(['perm:payment.confirm', Idempotent::class])->name('confirm');
        Route::post('/refund', [PaymentController::class, 'refund'])->middleware(['perm:payment.refund', Idempotent::class])->name('refund');
        Route::post('/cancel', [PaymentController::class, 'cancel'])->middleware('perm:payment.create')->name('cancel');
    });
    Route::get('/sales/deals/{id}/money', [PaymentController::class, 'money'])->whereUuid('id')->middleware('perm:payment.read')->name('deals.money');

    // Комиссии
    Route::get('/finance/commissions', [CommissionController::class, 'index'])->middleware('perm:commission.read')->name('commissions.index');
    Route::post('/finance/commissions/approve', [CommissionController::class, 'approve'])->middleware('perm:commission.approve,ALL')->name('commissions.approve');
    Route::post('/finance/commissions/pay', [CommissionController::class, 'pay'])->middleware('perm:commission.approve,ALL')->name('commissions.pay');

    // Настройки: правила комиссий, тарифы и работы, документы
    Route::get('/settings/commission-rules', [CommissionController::class, 'rules'])->middleware('perm:commission_rule.manage,ALL')->name('commission-rules.index');
    Route::post('/settings/commission-rules', [CommissionController::class, 'storeRule'])->middleware('perm:commission_rule.manage,ALL')->name('commission-rules.store');
    Route::put('/settings/commission-rules/{id}', [CommissionController::class, 'updateRule'])->whereUuid('id')->middleware('perm:commission_rule.manage,ALL')->name('commission-rules.update');

    Route::get('/settings/tariffs', [TariffController::class, 'index'])->middleware('perm:reference.manage,ALL')->name('tariffs.index');
    Route::post('/settings/tariffs', [TariffController::class, 'store'])->middleware('perm:reference.manage,ALL')->name('tariffs.store');
    Route::put('/settings/tariffs/{id}', [TariffController::class, 'update'])->whereUuid('id')->middleware('perm:reference.manage,ALL')->name('tariffs.update');
    Route::post('/settings/work-items', [TariffController::class, 'storeWorkItem'])->middleware('perm:reference.manage,ALL')->name('work-items.store');
    Route::put('/settings/work-items/{id}', [TariffController::class, 'updateWorkItem'])->whereUuid('id')->middleware('perm:reference.manage,ALL')->name('work-items.update');
    Route::get('/sales/tariffs', [TariffController::class, 'list'])->middleware('auth.only')->name('tariffs.list');

    Route::get('/settings/documents', [DocumentController::class, 'settings'])->middleware('perm:settings.manage,ALL')->name('documents.settings');
    Route::put('/settings/documents', [DocumentController::class, 'saveSettings'])->middleware('perm:settings.manage,ALL')->name('documents.save');
    Route::put('/clients/{id}/requisites', [DocumentController::class, 'saveRequisites'])->whereUuid('id')->middleware('perm:client.update')->name('requisites.update');

    // Личные сдельные ставки (карточка сотрудника): свои — каждый, чужие — CEO (payroll.manage)
    Route::get('/team/employees/{id}/rates', [TariffController::class, 'rates'])->whereUuid('id')->middleware('auth.only')->name('rates.show');
    Route::put('/team/employees/{id}/rates', [TariffController::class, 'saveRates'])->whereUuid('id')->middleware('perm:payroll.manage,ALL')->name('rates.update');

    // Файлы
    Route::post('/files', [FileController::class, 'store'])->middleware(['auth.only', Idempotent::class])->name('files.store');
    Route::get('/files', [FileController::class, 'index'])->middleware('auth.only')->name('files.index');
    Route::get('/files/{id}/download', [FileController::class, 'download'])->whereUuid('id')->middleware('auth.only')->name('files.download');
    Route::delete('/files/{id}', [FileController::class, 'destroy'])->whereUuid('id')->middleware('auth.only')->name('files.destroy');
});
