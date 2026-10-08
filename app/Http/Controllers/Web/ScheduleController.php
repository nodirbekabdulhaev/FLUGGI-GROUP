<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Services\ScheduleService;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ScheduleController extends Controller
{
    public function __construct(protected ScheduleService $svc) {}

    protected function rules(bool $multi): array
    {
        return [
            'group_id' => ['required', Lookup::exists('groups')],
            'teacher_id' => ['nullable', Lookup::exists('teachers')],
            'room_id' => ['nullable', Lookup::exists('rooms')],
            ($multi ? 'weekdays' : 'weekday') => $multi ? 'required|array|min:1' : 'required|integer|between:1,7',
            'weekdays.*' => 'integer|between:1,7',
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function index(Request $request)
    {
        $q = Schedule::where('is_active', true)->with(['group:id,name,teacher_id', 'teacher:id,full_name', 'room:id,name']);
        if ($request->user()->restrictedToOwnGroups()) {
            $q->whereIn('group_id', Group::visibleTo($request->user())->select('id'));
        }
        foreach (['group_id', 'teacher_id', 'room_id'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->query($f));
            }
        }
        $byDay = $q->orderBy('start_time')->get()->groupBy('weekday');

        return view('schedule.index', [
            'byDay' => $byDay,
            'groups' => Group::visibleTo($request->user())->orderBy('name')->pluck('name', 'id'),
            'teachers' => Lookup::teachers(), 'rooms' => Lookup::rooms(),
        ]);
    }

    public function create(Request $request)
    {
        return view('schedule.form', ['schedule' => null, 'values' => [
            'group_id' => $request->query('group_id'), 'start_date' => today()->toDateString(), 'weekdays' => [], 'start_time' => '18:00', 'end_time' => '19:30',
        ]]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(true));
        $days = array_unique(array_map('intval', $data['weekdays']));
        unset($data['weekdays']);

        \DB::transaction(function () use ($data, $days) {
            $messages = [];
            foreach ($days as $d) {
                try {
                    $this->svc->create($data + ['weekday' => $d, 'is_active' => true]);
                } catch (ValidationException $e) {
                    $day = Schedule::WEEKDAYS[$d];
                    foreach ($e->errors()['conflict'] ?? [] as $m) {
                        $messages[] = "{$day}: {$m}";
                    }
                }
            }
            if ($messages) {
                throw ValidationException::withMessages(['conflict' => $messages]);
            }
        });

        return redirect()->route('schedule.index', ['group_id' => $data['group_id']])->with('ok', __('Расписание создано, занятия сгенерированы'));
    }

    public function edit(Schedule $schedule)
    {
        $values = $schedule->only(['group_id', 'teacher_id', 'room_id', 'weekday', 'is_active']) + [
            'start_time' => ftime($schedule->start_time), 'end_time' => ftime($schedule->end_time),
            'start_date' => $schedule->start_date->toDateString(), 'end_date' => $schedule->end_date?->toDateString(),
        ];

        return view('schedule.form', ['schedule' => $schedule, 'values' => $values]);
    }

    public function update(Request $request, Schedule $schedule)
    {
        $data = $request->validate(collect($this->rules(false))->except(['group_id', 'weekdays.*'])->all());
        $data['is_active'] = $request->boolean('is_active');
        $this->svc->update($schedule, $data);

        return redirect()->route('schedule.index', ['group_id' => $schedule->group_id])->with('ok', __('Сохранено'));
    }

    public function destroy(Schedule $schedule)
    {
        $this->svc->delete($schedule);

        return redirect()->route('schedule.index')->with('ok', __('Удалено'));
    }

    /** Live conflict check used by the form before saving (JSON). */
    public function check(Request $request)
    {
        $d = $request->validate([
            'group_id' => 'nullable|integer', 'teacher_id' => 'nullable|integer', 'room_id' => 'nullable|integer',
            'weekdays' => 'required|array', 'weekdays.*' => 'integer|between:1,7',
            'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i|after:start_time',
            'start_date' => 'required|date', 'end_date' => 'nullable|date', 'except' => 'nullable|integer',
        ]);
        $out = [];
        foreach ($d['weekdays'] as $day) {
            foreach ($this->svc->conflicts($d + ['weekday' => $day], $d['except'] ?? null) as $c) {
                $out[] = Schedule::WEEKDAYS[$day].': '.$c['message'];
            }
        }

        return response()->json(['conflicts' => $out]);
    }
}
