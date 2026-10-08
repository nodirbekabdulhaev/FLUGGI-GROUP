<?php

use App\Http\Controllers\Web as W;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/
Route::get('locale/{locale}', [W\HomeController::class, 'locale'])->name('locale');
Route::post('telegram/webhook/{secret}', [W\TelegramController::class, 'webhook'])->name('telegram.webhook');

if (config('app.demo')) {
    Route::get('demo/{role}', [W\DemoController::class, 'login'])->name('demo.login');
}

Route::middleware('guest')->group(function () {
    Route::get('login', [W\AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [W\AuthController::class, 'login'])->name('login.attempt');
    Route::get('forgot-password', [W\AuthController::class, 'forgotForm'])->name('password.request');
    Route::post('forgot-password', [W\AuthController::class, 'sendReset'])->middleware('throttle:5,1')->name('password.email');
    Route::get('reset-password/{token}', [W\AuthController::class, 'resetForm'])->name('password.reset');
    Route::post('reset-password', [W\AuthController::class, 'reset'])->name('password.update');
});

/** Resource with a single {id} parameter (CrudController family). */
$crud = function (string $uri, string $controller, string $name, array $except = ['show']) {
    Route::resource($uri, $controller)->parameters([$uri => 'id'])->names($name)->except($except);
};

Route::middleware('auth')->group(function () use ($crud) {
    Route::post('logout', [W\AuthController::class, 'logout'])->name('logout');
    Route::get('/', [W\HomeController::class, 'index'])->name('home');
    Route::get('profile', [W\AuthController::class, 'profile'])->name('profile');
    Route::put('profile', [W\AuthController::class, 'updateProfile'])->name('profile.update');
    Route::post('branch', [W\HomeController::class, 'switchBranch'])->name('branch.switch');
    Route::get('search/json', [W\HomeController::class, 'search'])->name('search.json');
    Route::get('notifications/bell', [W\NotificationController::class, 'bell'])->name('notifications.bell');
    Route::post('notifications/read', [W\NotificationController::class, 'readAll'])->name('notifications.read');
    Route::get('files/{kind}/{id}', [W\FileController::class, 'show'])->name('files.show');

    Route::get('dashboard', [W\DashboardController::class, 'index'])->middleware('can:dashboard.view')->name('dashboard');

    /* ---------------- CRM ---------------- */
    Route::middleware('can:leads.view')->group(function () {
        Route::get('leads/funnel', [W\LeadController::class, 'funnel'])->name('leads.funnel');
        Route::get('leads', [W\LeadController::class, 'index'])->name('leads.index');
        Route::get('leads/{lead}', [W\LeadController::class, 'show'])->whereNumber('lead')->name('leads.show');
    });
    Route::middleware('can:leads.manage')->group(function () {
        Route::get('leads/create', [W\LeadController::class, 'create'])->name('leads.create');
        Route::post('leads', [W\LeadController::class, 'store'])->name('leads.store');
        Route::get('leads/{lead}/edit', [W\LeadController::class, 'edit'])->name('leads.edit');
        Route::put('leads/{lead}', [W\LeadController::class, 'update'])->name('leads.update');
        Route::post('leads/{lead}/status', [W\LeadController::class, 'status'])->name('leads.status');
        Route::post('leads/{lead}/notes', [W\LeadController::class, 'note'])->name('leads.note');
        Route::post('leads/{lead}/convert', [W\LeadController::class, 'convert'])->name('leads.convert');
    });
    Route::delete('leads/{lead}', [W\LeadController::class, 'destroy'])->middleware('can:leads.delete')->name('leads.destroy');

    /* ---------------- Education ---------------- */
    Route::middleware('can:students.view')->group(function () {
        Route::get('students', [W\StudentController::class, 'index'])->name('students.index');
        Route::get('parents', [W\GuardianController::class, 'index'])->name('guardians.index');
    });
    Route::middleware('can:students.manage')->group(function () {
        Route::get('students/create', [W\StudentController::class, 'create'])->name('students.create');
        Route::post('students', [W\StudentController::class, 'store'])->name('students.store');
        Route::get('students/{student}/edit', [W\StudentController::class, 'edit'])->name('students.edit');
        Route::put('students/{student}', [W\StudentController::class, 'update'])->name('students.update');
        Route::post('students/{student}/status', [W\StudentController::class, 'status'])->name('students.status');
        Route::post('students/{student}/guardians', [W\StudentController::class, 'attachGuardian'])->name('students.guardians.attach');
        Route::delete('students/{student}/guardians/{guardian}', [W\StudentController::class, 'detachGuardian'])->name('students.guardians.detach');
        Route::post('enrollments', [W\EnrollmentController::class, 'store'])->name('enrollments.store');
        Route::post('enrollments/{enrollment}/transfer', [W\EnrollmentController::class, 'transfer'])->name('enrollments.transfer');
        Route::post('enrollments/{enrollment}/{action}', [W\EnrollmentController::class, 'action'])->whereIn('action', ['freeze', 'unfreeze', 'complete', 'leave', 'renew'])->name('enrollments.action');
        Route::put('enrollments/{enrollment}', [W\EnrollmentController::class, 'update'])->name('enrollments.update');
        Route::get('parents/create', [W\GuardianController::class, 'create'])->name('guardians.create');
        Route::post('parents', [W\GuardianController::class, 'store'])->name('guardians.store');
        Route::get('parents/{id}/edit', [W\GuardianController::class, 'edit'])->name('guardians.edit');
        Route::put('parents/{id}', [W\GuardianController::class, 'update'])->name('guardians.update');
    });
    Route::middleware('can:students.delete')->group(function () {
        Route::delete('students/{student}', [W\StudentController::class, 'destroy'])->name('students.destroy');
        Route::delete('parents/{id}', [W\GuardianController::class, 'destroy'])->name('guardians.destroy');
    });
    // show: students.view, or a teacher looking at a student of their own group (checked in the controller)
    Route::get('students/{student}', [W\StudentController::class, 'show'])->whereNumber('student')->name('students.show');
    Route::get('parents/{id}', [W\GuardianController::class, 'show'])->middleware('can:students.view')->whereNumber('id')->name('guardians.show');

    $crud('courses', W\CourseController::class, 'courses');

    Route::middleware('canany:groups.view,groups.view_own')->get('groups', [W\GroupController::class, 'index'])->name('groups.index');
    Route::middleware('can:groups.manage')->group(function () {
        Route::get('groups/create', [W\GroupController::class, 'create'])->name('groups.create');
        Route::post('groups', [W\GroupController::class, 'store'])->name('groups.store');
        Route::get('groups/{group}/edit', [W\GroupController::class, 'edit'])->name('groups.edit');
        Route::put('groups/{group}', [W\GroupController::class, 'update'])->name('groups.update');
    });
    Route::delete('groups/{group}', [W\GroupController::class, 'destroy'])->middleware('can:groups.delete')->name('groups.destroy');
    Route::get('groups/{group}', [W\GroupController::class, 'show'])->whereNumber('group')->name('groups.show');

    /* ---------------- Schedule / lessons / attendance ---------------- */
    Route::middleware('can:schedule.view')->group(function () {
        Route::get('schedule', [W\ScheduleController::class, 'index'])->name('schedule.index');
    });
    Route::middleware('can:schedule.manage')->group(function () {
        Route::get('schedule/create', [W\ScheduleController::class, 'create'])->name('schedule.create');
        Route::post('schedule', [W\ScheduleController::class, 'store'])->name('schedule.store');
        Route::get('schedule/{schedule}/edit', [W\ScheduleController::class, 'edit'])->name('schedule.edit');
        Route::put('schedule/{schedule}', [W\ScheduleController::class, 'update'])->name('schedule.update');
        Route::delete('schedule/{schedule}', [W\ScheduleController::class, 'destroy'])->name('schedule.destroy');
        Route::post('schedule/check', [W\ScheduleController::class, 'check'])->name('schedule.check');
        Route::post('lessons/{lesson}/cancel', [W\LessonController::class, 'cancel'])->name('lessons.cancel');
        Route::post('lessons/{lesson}/reschedule', [W\LessonController::class, 'reschedule'])->name('lessons.reschedule');
        Route::post('lessons', [W\LessonController::class, 'store'])->name('lessons.store');
    });
    Route::get('lessons', [W\LessonController::class, 'index'])->middleware('canany:schedule.view,attendance.mark')->name('lessons.index');
    Route::get('lessons/{lesson}', [W\LessonController::class, 'show'])->middleware('canany:schedule.view,attendance.mark')->name('lessons.show');
    Route::post('lessons/{lesson}/attendance', [W\LessonController::class, 'mark'])->middleware('can:attendance.mark')->name('lessons.mark');
    Route::get('attendance', [W\AttendanceController::class, 'index'])->middleware('can:attendance.view')->name('attendance.index');

    /* ---------------- Finance ---------------- */
    Route::prefix('finance')->group(function () {
        Route::middleware('can:payments.view')->get('payments', [W\PaymentController::class, 'index'])->name('payments.index');
        Route::middleware('can:payments.create')->group(function () {
            Route::get('payments/create', [W\PaymentController::class, 'create'])->name('payments.create');
            Route::post('payments', [W\PaymentController::class, 'store'])->name('payments.store');
            Route::get('students/{student}/balance', [W\PaymentController::class, 'balance'])->name('payments.balance');
        });
        Route::middleware('can:payments.correct')->group(function () {
            Route::get('payments/{payment}/edit', [W\PaymentController::class, 'edit'])->name('payments.edit');
            Route::put('payments/{payment}', [W\PaymentController::class, 'update'])->name('payments.update');
            Route::delete('payments/{payment}', [W\PaymentController::class, 'destroy'])->name('payments.destroy');
        });
        Route::middleware('can:debts.view')->get('debts', [W\DebtController::class, 'index'])->name('debts.index');

        Route::middleware('can:expenses.view')->get('expenses', [W\ExpenseController::class, 'index'])->name('expenses.index');
        Route::middleware('can:expenses.manage')->group(function () {
            Route::get('expenses/create', [W\ExpenseController::class, 'create'])->name('expenses.create');
            Route::post('expenses', [W\ExpenseController::class, 'store'])->name('expenses.store');
            Route::get('expenses/{id}/edit', [W\ExpenseController::class, 'edit'])->name('expenses.edit');
            Route::put('expenses/{id}', [W\ExpenseController::class, 'update'])->name('expenses.update');
            Route::delete('expenses/{id}', [W\ExpenseController::class, 'destroy'])->name('expenses.destroy');
        });

        Route::middleware('can:salaries.view')->get('salaries', [W\SalaryController::class, 'index'])->name('salaries.index');
        Route::middleware('can:salaries.manage')->group(function () {
            Route::post('salaries/accrue', [W\SalaryController::class, 'accrue'])->name('salaries.accrue');
            Route::post('salaries/pay', [W\SalaryController::class, 'pay'])->name('salaries.pay');
        });
    });

    /* ---------------- Staff / branches ---------------- */
    $crud('teachers', W\TeacherController::class, 'teachers', []);
    $crud('employees', W\EmployeeController::class, 'employees');
    $crud('branches', W\BranchController::class, 'branches');
    $crud('rooms', W\RoomController::class, 'rooms');

    /* ---------------- Analytics ---------------- */
    Route::prefix('reports')->controller(W\ReportController::class)->group(function () {
        Route::get('sales', 'sales')->middleware('can:reports.sales')->name('reports.sales');
        Route::get('sources', 'sources')->middleware('can:reports.sales')->name('reports.sources');
        Route::get('managers', 'managers')->middleware('can:reports.sales')->name('reports.managers');
        Route::get('finance', 'finance')->middleware('can:reports.finance')->name('reports.finance');
        Route::get('students', 'students')->middleware('can:reports.students')->name('reports.students');
        Route::get('attendance', 'attendance')->middleware('can:reports.attendance')->name('reports.attendance');
        Route::get('teachers', 'teachers')->middleware('can:reports.teachers')->name('reports.teachers');
        Route::get('export/{type}', 'export')->name('reports.export');
    });

    /* ---------------- Notifications / Telegram ---------------- */
    Route::middleware('can:telegram.manage')->prefix('notifications')->group(function () {
        Route::get('telegram', [W\TelegramController::class, 'index'])->name('telegram.index');
        Route::post('telegram/process', [W\TelegramController::class, 'process'])->name('telegram.process');
        Route::post('telegram/test', [W\TelegramController::class, 'test'])->name('telegram.test');
        Route::post('telegram/{notification}/retry', [W\TelegramController::class, 'retry'])->name('telegram.retry');
        Route::post('telegram/link', [W\TelegramController::class, 'link'])->name('telegram.link');
    });

    /* ---------------- Settings ---------------- */
    Route::middleware('can:settings.manage')->prefix('settings')->group(function () {
        Route::get('/', [W\SettingsController::class, 'index'])->name('settings.index');
        Route::put('/', [W\SettingsController::class, 'update'])->name('settings.update');
        Route::get('dictionaries', [W\SettingsController::class, 'dictionaries'])->name('settings.dictionaries');
        foreach (['sources', 'statuses', 'methods', 'categories', 'reasons', 'subjects'] as $dict) {
            $ctrl = 'App\\Http\\Controllers\\Web\\Dictionary\\'.ucfirst($dict).'Controller';
            Route::resource('dictionaries/'.$dict, $ctrl)->parameters([$dict => 'id'])->names('dict_'.$dict)->except(['show']);
        }
    });
    Route::middleware('can:backup.manage')->prefix('settings/backups')->group(function () {
        Route::post('/', [W\SettingsController::class, 'backup'])->name('backups.create');
        Route::get('{name}', [W\SettingsController::class, 'download'])->name('backups.download');
        Route::post('{name}/restore', [W\SettingsController::class, 'restore'])->name('backups.restore');
    });
    $crud('users', W\UserController::class, 'users');
    Route::middleware('can:roles.manage')->group(function () {
        Route::resource('roles', W\RoleController::class)->parameters(['roles' => 'role'])->except(['show']);
    });
    Route::get('audit', [W\AuditController::class, 'index'])->middleware('can:audit.view')->name('audit.index');
});
