<?php

use App\Http\Controllers\Finance\CostLinesController;
use App\Http\Controllers\Finance\ExpensesController;
use App\Http\Controllers\Finance\FinanceController;
use App\Http\Controllers\Finance\FinanceSettingsController;
use App\Http\Controllers\Finance\IncomesController;
use App\Http\Controllers\Projects\ProjectsController;
use App\Http\Controllers\Projects\TasksController;
use App\Http\Controllers\Projects\TemplatesController;
use Illuminate\Support\Facades\Route;

// Проекты, задачи, шаблоны (ТЗ §19–24, §62) и финансы (ТЗ §25–27). Видимость — App\Services\Projects\ProjectAccess.
$uuid = '[0-9a-fA-F-]{36}';

Route::redirect('/projects', '/projects/all')->middleware('perm:project.read');
Route::get('/projects/{view}', [ProjectsController::class, 'index'])->whereIn('view', ['all', 'active', 'overdue', 'completed'])->middleware('perm:project.read')->name('projects.index');
Route::get('/projects/{id}', [ProjectsController::class, 'show'])->where('id', $uuid)->middleware('perm:project.read')->name('projects.show');
Route::patch('/projects/{id}', [ProjectsController::class, 'update'])->where('id', $uuid)->middleware('perm:project.update')->name('projects.update');
Route::post('/projects/{id}/status', [ProjectsController::class, 'status'])->where('id', $uuid)->middleware('perm:project.update')->name('projects.status');
Route::post('/projects/{id}/apply-template', [ProjectsController::class, 'applyTemplate'])->where('id', $uuid)->middleware('perm:project.update')->name('projects.template');
Route::post('/projects/{id}/members', [ProjectsController::class, 'addMember'])->where('id', $uuid)->middleware('perm:project.assign')->name('projects.members.store');
Route::patch('/projects/{id}/members/{member}', [ProjectsController::class, 'updateMember'])->where(['id' => $uuid, 'member' => $uuid])->middleware('perm:project.assign')->name('projects.members.update');

Route::get('/tasks', [TasksController::class, 'index'])->middleware('perm:task.read')->name('tasks.index');
Route::post('/tasks', [TasksController::class, 'store'])->middleware('perm:task.create')->name('tasks.store');
Route::patch('/tasks/{id}', [TasksController::class, 'update'])->where('id', $uuid)->middleware('perm:task.update')->name('tasks.update');
Route::post('/tasks/{id}/move', [TasksController::class, 'move'])->where('id', $uuid)->middleware('perm:task.update')->name('tasks.move');
Route::delete('/tasks/{id}', [TasksController::class, 'destroy'])->where('id', $uuid)->middleware('perm:task.update')->name('tasks.destroy');
Route::post('/tasks/{id}/comments', [TasksController::class, 'comment'])->where('id', $uuid)->middleware('perm:task.read')->name('tasks.comment');

Route::get('/settings/templates', [TemplatesController::class, 'index'])->middleware('perm:reference.manage')->name('settings.templates');
Route::post('/settings/templates', [TemplatesController::class, 'store'])->middleware('perm:reference.manage')->name('settings.templates.store');
Route::put('/settings/templates/{id}', [TemplatesController::class, 'update'])->where('id', $uuid)->middleware('perm:reference.manage')->name('settings.templates.update');

// Финансы — только по правам finance.* (по умолчанию — CEO)
Route::redirect('/finance', '/finance/revenue')->middleware('perm:finance.read');
Route::get('/finance/revenue', [FinanceController::class, 'revenue'])->middleware('perm:finance.read')->name('finance.revenue');
Route::get('/finance/profit', [FinanceController::class, 'profit'])->middleware('perm:finance.read')->name('finance.profit');
Route::get('/finance/expenses', [ExpensesController::class, 'index'])->middleware('perm:finance.read')->name('finance.expenses');
Route::post('/finance/expenses', [ExpensesController::class, 'store'])->middleware('perm:expense.create')->name('finance.expenses.store');
Route::patch('/finance/expenses/{id}', [ExpensesController::class, 'update'])->where('id', $uuid)->middleware('perm:expense.update')->name('finance.expenses.update');
Route::delete('/finance/expenses/{id}', [ExpensesController::class, 'destroy'])->where('id', $uuid)->middleware('perm:expense.update')->name('finance.expenses.destroy');
Route::get('/finance/incomes', [IncomesController::class, 'index'])->middleware('perm:finance.company.read,ALL')->name('finance.incomes');
Route::post('/finance/incomes', [IncomesController::class, 'store'])->middleware('perm:finance.company.read,ALL')->name('finance.incomes.store');
Route::put('/finance/incomes/{id}', [IncomesController::class, 'update'])->where('id', $uuid)->middleware('perm:finance.company.read,ALL')->name('finance.incomes.update');
Route::delete('/finance/incomes/{id}', [IncomesController::class, 'destroy'])->where('id', $uuid)->middleware('perm:finance.company.read,ALL')->name('finance.incomes.destroy');
Route::patch('/finance/cost-lines/{id}', [CostLinesController::class, 'update'])->where('id', $uuid)->middleware('perm:expense.update')->name('finance.cost-lines.update');
Route::post('/finance/cost-lines/{id}/accrue', [CostLinesController::class, 'accrue'])->where('id', $uuid)->middleware('perm:expense.update')->name('finance.cost-lines.accrue');
Route::post('/finance/cost-lines/{id}/cancel', [CostLinesController::class, 'cancel'])->where('id', $uuid)->middleware('perm:expense.update')->name('finance.cost-lines.cancel');

Route::get('/settings/finance', [FinanceSettingsController::class, 'index'])->middleware('perm:reference.manage')->name('settings.finance');
Route::post('/settings/finance/categories', [FinanceSettingsController::class, 'storeCategory'])->middleware('perm:reference.manage,ALL')->name('settings.finance.categories.store');
Route::put('/settings/finance/categories/{id}', [FinanceSettingsController::class, 'updateCategory'])->where('id', $uuid)->middleware('perm:reference.manage,ALL')->name('settings.finance.categories.update');
Route::delete('/settings/finance/categories/{id}', [FinanceSettingsController::class, 'destroyCategory'])->where('id', $uuid)->middleware('perm:reference.manage,ALL')->name('settings.finance.categories.destroy');
Route::put('/settings/finance/overhead', [FinanceSettingsController::class, 'overhead'])->middleware('perm:finance.company.read,ALL')->name('settings.finance.overhead');
