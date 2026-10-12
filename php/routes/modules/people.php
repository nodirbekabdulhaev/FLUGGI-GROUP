<?php

use App\Http\Controllers\People\AttendanceController;
use App\Http\Controllers\People\AuditController;
use App\Http\Controllers\People\EmployeesController;
use App\Http\Controllers\People\KpiController;
use App\Http\Controllers\People\PayrollController;
use App\Http\Controllers\People\RolesController;
use App\Http\Controllers\People\SchedulesController;
use App\Http\Controllers\People\SettingsController;
use Illuminate\Support\Facades\Route;

// Команда, роли, KPI, посещаемость, графики, зарплата, настройки, аудит (ТЗ §3, §28–37, §45)
$uuid = '[0-9a-fA-F-]{36}';
$manage = 'perm:employee.manage,ALL';

Route::redirect('/team', '/team/employees')->middleware('perm:employee.read');
Route::get('/team/my-rates', [EmployeesController::class, 'myRates'])->middleware('auth.only')->name('team.my-rates');
Route::get('/team/{role}', [EmployeesController::class, 'index'])->whereIn('role', ['employees', 'managers', 'rop', 'executors'])->middleware('perm:employee.read')->name('team.index');
Route::post('/team/employees', [EmployeesController::class, 'store'])->middleware($manage)->name('team.store');
Route::patch('/team/employees/{id}', [EmployeesController::class, 'update'])->where('id', $uuid)->middleware($manage)->name('team.update');
Route::post('/team/employees/{id}/block', [EmployeesController::class, 'block'])->where('id', $uuid)->middleware($manage)->name('team.block');
Route::post('/team/employees/{id}/unblock', [EmployeesController::class, 'unblock'])->where('id', $uuid)->middleware($manage)->name('team.unblock');
Route::post('/team/employees/{id}/reset-password', [EmployeesController::class, 'resetPassword'])->where('id', $uuid)->middleware($manage)->name('team.reset');
Route::delete('/team/employees/{id}', [EmployeesController::class, 'destroy'])->where('id', $uuid)->middleware($manage)->name('team.destroy');
Route::put('/team/employees/{id}/rates', [EmployeesController::class, 'saveRates'])->where('id', $uuid)->middleware('perm:payroll.manage,ALL')->name('team.rates');

Route::get('/kpi', [KpiController::class, 'index'])->middleware('perm:kpi.read')->name('kpi');
Route::put('/kpi/targets', [KpiController::class, 'targets'])->middleware('perm:kpi.target.manage')->name('kpi.targets');

Route::get('/attendance', [AttendanceController::class, 'index'])->middleware('perm:attendance.read')->name('attendance');
Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn'])->middleware('auth.only')->name('attendance.check-in');
Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut'])->middleware('auth.only')->name('attendance.check-out');
Route::put('/attendance', [AttendanceController::class, 'upsert'])->middleware('perm:attendance.manage')->name('attendance.upsert');

Route::get('/finance/payroll', [PayrollController::class, 'index'])->middleware('perm:payroll.read')->name('payroll');
Route::post('/finance/payroll/calculate', [PayrollController::class, 'calculate'])->middleware('perm:payroll.manage,ALL')->name('payroll.calculate');
Route::patch('/finance/payroll/{id}', [PayrollController::class, 'update'])->where('id', $uuid)->middleware('perm:payroll.manage,ALL')->name('payroll.update');
Route::post('/finance/payroll/approve', [PayrollController::class, 'approve'])->middleware('perm:payroll.manage,ALL')->name('payroll.approve');
Route::post('/finance/payroll/pay', [PayrollController::class, 'pay'])->middleware('perm:payroll.manage,ALL')->name('payroll.pay');

Route::get('/settings', [SettingsController::class, 'index'])
    ->middleware('perm:settings.manage|role.manage|employee.manage|audit.read|reference.manage|commission_rule.manage|schedule.manage')->name('settings');
Route::get('/settings/roles', [RolesController::class, 'index'])->middleware('perm:role.manage,ALL')->name('settings.roles');
Route::put('/settings/roles/{id}', [RolesController::class, 'update'])->where('id', $uuid)->middleware('perm:role.manage,ALL')->name('settings.roles.update');
Route::get('/settings/schedules', [SchedulesController::class, 'index'])->middleware('perm:schedule.manage')->name('settings.schedules');
Route::post('/settings/schedules', [SchedulesController::class, 'store'])->middleware('perm:schedule.manage,ALL')->name('settings.schedules.store');
Route::put('/settings/schedules/{id}', [SchedulesController::class, 'update'])->where('id', $uuid)->middleware('perm:schedule.manage,ALL')->name('settings.schedules.update');
Route::get('/settings/audit', [AuditController::class, 'index'])->middleware('perm:audit.read,ALL')->name('settings.audit');
