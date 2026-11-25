<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\People\AttendanceCheckout;
use App\Services\People\KpiService;
use App\Services\People\PeopleService;
use App\Support\Format;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Посещаемость (ТЗ §35): итоги и журнал за период; приход/уход — только менеджеры и РОП.
 * «Не отмечен уход — на утверждение»: дни, закрытые автоматически, утверждает или отклоняет CEO.
 */
class AttendanceController extends Controller
{
    public function index(Request $request): View
    {
        $today = Format::today()->toDateString();
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'tab' => ['nullable', Rule::in(['summary', 'records', 'approval'])],
            'edit' => ['nullable', 'uuid'],
        ]);
        $from = $f['from'] ?? substr($today, 0, 8).'01';
        $to = $f['to'] ?? $today;
        $manage = access()->can('attendance.manage');
        $approver = AttendanceCheckout::canApprove();
        $tab = $f['tab'] ?? 'summary';
        $data = [
            'from' => $from, 'to' => $to, 'tab' => $tab === 'approval' && ! $approver ? 'summary' : $tab, 'manage' => $manage,
            'approver' => $approver,
            'pending' => $approver ? AttendanceCheckout::pending() : collect(),
            'canSettings' => access()->can('attendance.manage', 'ALL') || access()->can('settings.manage', 'ALL'),
            'checkoutSettings' => AttendanceCheckout::settings(),
            'summary' => PeopleService::summary($from, $to),
            'records' => PeopleService::list($from, $to),
            'today' => PeopleService::today(),
        ];
        if ($manage) {
            $data['people'] = KpiService::users('attendance.manage')->where('users.status', 'ACTIVE')
                ->whereHas('role', fn ($q) => $q->whereIn('code', PeopleService::ATTENDANCE_ROLES))->orderBy('full_name')->pluck('full_name', 'users.id')->all();
            if (! empty($f['edit'])) {
                $data['editing'] = Attendance::with('user:id,full_name')->whereIn('user_id', array_keys($data['people']))->findOrFail($f['edit']);
            }
        }

        return view('people.attendance', $data);
    }

    public function checkIn(Request $request): RedirectResponse
    {
        $c = $request->validate(['comment' => ['nullable', 'string', 'max:500']])['comment'] ?? null;
        PeopleService::checkIn($c);

        return back()->with('ok', t('attendance.checkedIn'));
    }

    public function checkOut(Request $request): RedirectResponse
    {
        $c = $request->validate(['comment' => ['nullable', 'string', 'max:500']])['comment'] ?? null;
        PeopleService::checkOut($c);

        return back()->with('ok', t('attendance.checkedOut'));
    }

    /** Утвердить день без отметки ухода (время ухода можно исправить). */
    public function approve(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate(['check_out' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/']], [], ['check_out' => t('attendance.out')]);
        AttendanceCheckout::approve($id, $data['check_out'] ?? null);

        return redirect('/attendance?tab=approval')->with('ok', t('attendance.checkout.approvedOk'));
    }

    /** Отклонить: сотрудник не работал — день считается прогулом. */
    public function reject(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']], [], ['reason' => t('attendance.checkout.reason')]);
        AttendanceCheckout::reject($id, filled($data['reason'] ?? null) ? trim($data['reason']) : null);

        return redirect('/attendance?tab=approval')->with('ok', t('attendance.checkout.rejectedOk'));
    }

    /** Настройки контроля ухода: напоминание и авто-уход (минут после конца графика). */
    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reminderGraceMinutes' => ['required', 'integer', 'min:0', 'max:240'],
            'autoCheckoutDelayMinutes' => ['required', 'integer', 'min:0', 'max:720', 'gte:reminderGraceMinutes'],
        ], ['autoCheckoutDelayMinutes.gte' => t('attendance.checkout.errors.delayBeforeReminder')], [
            'reminderGraceMinutes' => t('attendance.checkout.reminderGrace'), 'autoCheckoutDelayMinutes' => t('attendance.checkout.autoDelay'),
        ]);
        AttendanceCheckout::saveSettings($data + ['checkoutControl' => $request->boolean('checkoutControl')]);

        return back()->with('ok', t('attendance.checkout.settingsSaved'));
    }

    public function upsert(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(PeopleService::ATTENDANCE_STATUSES)],
            'check_in' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'check_out' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);
        PeopleService::upsertAttendance($data + ['check_in' => null, 'check_out' => null, 'comment' => null]);

        return redirect('/attendance?tab=records&from='.$request->input('from', $data['date']).'&to='.$request->input('to', $data['date']))->with('ok', t('attendance.saved'));
    }
}
