<?php

use App\Http\Controllers\Api as A;
use Illuminate\Support\Facades\Route;

/* Prefix /api/v1 is applied in bootstrap/app.php */

Route::get('ping', [A\AuthTokenController::class, 'ping']);

Route::post('auth/token', [A\AuthTokenController::class, 'store'])->middleware('throttle:10,1');

Route::post('webhooks/leads', A\LeadWebhookController::class)->middleware(['throttle:webhook', 'webhook']);

Route::middleware(['auth:sanctum', 'tenant', 'throttle:api'])->group(function () {
    Route::delete('auth/token', [A\AuthTokenController::class, 'destroy']);

    Route::get('students', [A\StudentApiController::class, 'index'])->middleware('can:students.view');
    Route::get('students/{id}', [A\StudentApiController::class, 'show'])->whereNumber('id')->middleware('can:students.view');
    Route::post('students', [A\StudentApiController::class, 'store'])->middleware('can:students.manage');

    Route::get('leads', [A\LeadApiController::class, 'index'])->middleware('can:leads.view');
    Route::get('leads/{id}', [A\LeadApiController::class, 'show'])->whereNumber('id')->middleware('can:leads.view');
    Route::post('leads', [A\LeadApiController::class, 'store'])->middleware('can:leads.manage');

    Route::get('groups', [A\GroupApiController::class, 'index'])->middleware('canany:groups.view,groups.view_own');
    Route::get('lessons', [A\LessonApiController::class, 'index'])->middleware('canany:schedule.view,attendance.mark');
    Route::post('attendance', [A\AttendanceApiController::class, 'store'])->middleware('can:attendance.mark');
    Route::post('payments', [A\PaymentApiController::class, 'store'])->middleware('can:payments.create');
});
