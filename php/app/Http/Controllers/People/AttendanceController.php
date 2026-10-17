<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\People\KpiService;
use App\Services\People\PeopleService;
use App\Support\Format;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Посещаемость (ТЗ §35): итоги и журнал за период; приход/уход — только менеджеры и РОП. */
class AttendanceController extends Controller
{
    public function index(Request $request): View
    {
        $today = Format::today()->toDateString();
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'tab' => ['nullable', Rule::in(['summary', 'records'])],
            'edit' => ['nullable', 'uuid'],
        ]);
        $from = $f['from'] ?? substr($today, 0, 8).'01';
        $to = $f['to'] ?? $today;
        $manage = access()->can('attendance.manage');
        $data = [
            'from' => $from, 'to' => $to, 'tab' => $f['tab'] ?? 'summary', 'manage' => $manage,
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
