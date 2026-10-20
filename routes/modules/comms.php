<?php

use App\Http\Controllers\Analytics\AnalyticsController;
use App\Http\Controllers\Analytics\ExportController;
use App\Http\Controllers\Analytics\SearchController;
use App\Http\Controllers\Automation\AutomationController;
use App\Http\Controllers\Automation\FollowUpController;
use App\Http\Controllers\Chat\ChatController;
use App\Http\Controllers\Integrations\InboxController;
use App\Http\Controllers\Integrations\IntegrationController;
use App\Http\Controllers\Notifications\NotificationController;
use App\Http\Controllers\Telegram\TelegramController;
use App\Http\Controllers\Todos\TodoController;
use Illuminate\Support\Facades\Route;

// Модуль «Коммуникации и автоматизация»: уведомления, Telegram, чат, дела, планировщик,
// follow-up, интеграции, «Входящие», аналитика, поиск, экспорт.

Route::middleware('auth.only')->group(function () {
    // Уведомления — личные: каждый видит только свои
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unread'])->name('notifications.unread');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.readAll');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id')->name('notifications.read');
    Route::get('/notifications/{id}/open', [NotificationController::class, 'open'])->whereUuid('id')->name('notifications.open');
    Route::put('/notifications/settings', [NotificationController::class, 'saveSettings'])->name('notifications.settings');

    // Telegram (ТЗ §53)
    Route::get('/me/telegram', [TelegramController::class, 'status'])->name('telegram.status');
    Route::post('/me/telegram/link', [TelegramController::class, 'link'])->name('telegram.link');
    Route::delete('/me/telegram', [TelegramController::class, 'unlink'])->name('telegram.unlink');
    Route::post('/me/telegram/test', [TelegramController::class, 'test'])->name('telegram.test');

    // Чат сотрудников (опрос, без websockets)
    Route::get('/chats', [ChatController::class, 'index'])->name('chats');
    Route::get('/chats/unread', [ChatController::class, 'unread'])->name('chats.unread');
    Route::get('/chats/contacts', [ChatController::class, 'contacts'])->name('chats.contacts');
    Route::post('/chats/direct', [ChatController::class, 'direct'])->name('chats.direct');
    Route::get('/chats/{id}/messages', [ChatController::class, 'messages'])->whereUuid('id')->name('chats.messages');
    Route::post('/chats/{id}/messages', [ChatController::class, 'send'])->whereUuid('id')->name('chats.send');
    Route::post('/chats/{id}/read', [ChatController::class, 'read'])->whereUuid('id')->name('chats.read');

    // Личные и регулярные дела; поручить другому — по праву task.create (проверка в сервисе)
    Route::get('/todos', [TodoController::class, 'index'])->name('todos');
    Route::get('/todos/dock', [TodoController::class, 'dock'])->name('todos.dock');
    Route::post('/todos', [TodoController::class, 'store'])->name('todos.store');
    Route::patch('/todos/{id}', [TodoController::class, 'update'])->whereUuid('id')->name('todos.update');
    Route::post('/todos/{id}/complete', [TodoController::class, 'complete'])->whereUuid('id')->name('todos.complete');
    Route::post('/todos/{id}/reopen', [TodoController::class, 'reopen'])->whereUuid('id')->name('todos.reopen');
    Route::delete('/todos/{id}', [TodoController::class, 'destroy'])->whereUuid('id')->name('todos.destroy');
    Route::post('/recurring-todos', [TodoController::class, 'recurringStore'])->name('recurring.store');
    Route::post('/recurring-todos/tax-calendar', [TodoController::class, 'taxCalendar'])->name('recurring.taxCalendar');
    Route::put('/recurring-todos/{id}', [TodoController::class, 'recurringUpdate'])->whereUuid('id')->name('recurring.update');
    Route::delete('/recurring-todos/{id}', [TodoController::class, 'recurringDestroy'])->whereUuid('id')->name('recurring.destroy');

    // Предпросмотр отчёта: CEO — компания, РОП — свой отдел (проверка в контроллере)
    Route::get('/reports/preview', [AutomationController::class, 'preview'])->name('reports.preview');

    // Глобальный поиск — каждая сущность в пределах прав
    Route::get('/search', [SearchController::class, 'search'])->name('search');
});

// Настройки автоматизации и реквизиты компании (CEO)
Route::middleware('perm:settings.manage,ALL')->group(function () {
    Route::get('/settings/automation', [AutomationController::class, 'index'])->name('automation');
    Route::put('/settings/automation', [AutomationController::class, 'save'])->name('automation.save');
    Route::put('/settings/automation/company', [AutomationController::class, 'saveCompany'])->name('automation.company');
    Route::post('/automation/jobs/{name}/run', [AutomationController::class, 'run'])->name('automation.run');

    Route::get('/settings/integrations', [IntegrationController::class, 'index'])->name('integrations');
    Route::post('/settings/integrations/forms', [IntegrationController::class, 'storeForm'])->name('integrations.forms.store');
    Route::put('/settings/integrations/forms/{id}', [IntegrationController::class, 'updateForm'])->whereUuid('id')->name('integrations.forms.update');
    Route::delete('/settings/integrations/forms/{id}', [IntegrationController::class, 'destroyForm'])->whereUuid('id')->name('integrations.forms.destroy');
    Route::get('/settings/integrations/forms/{id}/submissions', [IntegrationController::class, 'submissions'])->whereUuid('id')->name('integrations.forms.submissions');
    Route::put('/settings/integrations/meta', [IntegrationController::class, 'saveMeta'])->name('integrations.meta');
});

// Повторные продажи (ТЗ §38)
Route::middleware('perm:client.read')->group(function () {
    Route::get('/sales/follow-ups', [FollowUpController::class, 'index'])->name('followUps');
    Route::post('/sales/follow-ups/{id}/complete', [FollowUpController::class, 'complete'])->whereUuid('id')->name('followUps.complete');
    Route::get('/clients/{id}/insight', [AnalyticsController::class, 'insight'])->whereUuid('id')->name('clients.insight');
});

// «Входящие»: видимость — как у лидов
Route::get('/inbox', [InboxController::class, 'index'])->middleware('perm:lead.read')->name('inbox');
Route::get('/inbox/{id}/messages', [InboxController::class, 'messages'])->whereUuid('id')->middleware('perm:lead.read')->name('inbox.messages');
Route::post('/inbox/{id}/reply', [InboxController::class, 'reply'])->whereUuid('id')->middleware('perm:lead.update')->name('inbox.reply');
Route::post('/inbox/{id}/lead', [InboxController::class, 'lead'])->whereUuid('id')->middleware('perm:lead.create')->name('inbox.lead');

// Аналитика и экспорт (ТЗ §39–44)
Route::get('/analytics', [AnalyticsController::class, 'index'])->middleware('perm:analytics.read')->name('analytics');
Route::get('/exports/accountant-package', [ExportController::class, 'accountant'])->middleware(['perm:finance.company.read,ALL', 'perm:export.run'])->name('exports.accountant');
Route::get('/exports/{entity}', [ExportController::class, 'export'])->middleware('perm:export.run')->name('exports');
