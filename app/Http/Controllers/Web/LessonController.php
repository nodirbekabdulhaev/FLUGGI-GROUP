<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Lesson;
use App\Services\AttendanceService;
use App\Services\ScheduleService;
use App\Support\Lookup;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LessonController extends Controller
{
    public function __construct(protected ScheduleService $schedule, protected AttendanceService $attendance) {}

    protected function find(Request $request, int $id): Lesson
    {
        return Lesson::visibleTo($request->user())->with(['group.course', 'teacher', 'room'])->findOrFail($id);
    }

    public function index(Request $request)
    {
        $date = $request->filled('date') ? Carbon::parse($request->query('date')) : today();
        $to = $request->filled('to') ? Carbon::parse($request->query('to')) : $date;

        $q = Lesson::visibleTo($request->user())->with(['group:id,name', 'teacher:id,full_name', 'room:id,name'])
            ->withCount(['attendance as marked_count'])
            ->whereBetween('lesson_date', [$date->toDateString(), $to->toDateString()]);
        foreach (['group_id', 'teacher_id', 'status'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->query($f));
            }
        }

        return view('lessons.index', [
            'lessons' => $q->orderBy('lesson_date')->orderBy('start_time')->get(),
            'date' => $date, 'to' => $to,
            'groups' => Group::visibleTo($request->user())->orderBy('name')->pluck('name', 'id'),
            'teachers' => Lookup::teachers(), 'rooms' => Lookup::rooms(),
        ]);
    }

    public function show(Request $request, int $lesson)
    {
        $lesson = $this->find($request, $lesson);
        $marks = $lesson->attendance()->get()->keyBy('student_id');

        return view('lessons.show', [
            'lesson' => $lesson, 'students' => $this->attendance->roster($lesson), 'marks' => $marks,
            'canMark' => $request->user()->hasPermission('attendance.mark') && in_array($lesson->status, ['planned', 'held']) && ! ($lesson->lesson_date->isFuture() && ! $lesson->lesson_date->isToday()),
            'teachers' => Lookup::teachers(), 'rooms' => Lookup::rooms(),
        ]);
    }

    public function mark(Request $request, int $lesson)
    {
        $lesson = $this->find($request, $lesson);
        $data = $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.status' => 'required|in:present,absent,late,excused',
            'rows.*.comment' => 'nullable|string|max:255',
            'topic' => 'nullable|string|max:255',
        ]);
        $this->attendance->mark($lesson, $data['rows']);
        if (array_key_exists('topic', $data)) {
            $lesson->update(['topic' => $data['topic']]);
        }

        return redirect()->route('lessons.show', $lesson)->with('ok', __('Посещаемость сохранена'));
    }

    public function cancel(Request $request, int $lesson)
    {
        $lesson = $this->find($request, $lesson);
        $this->schedule->cancel($lesson, $request->validate(['reason' => 'nullable|string|max:255'])['reason'] ?? null);

        return back()->with('ok', __('Занятие отменено'));
    }

    public function reschedule(Request $request, int $lesson)
    {
        $lesson = $this->find($request, $lesson);
        $d = $request->validate([
            'lesson_date' => 'required|date', 'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i|after:start_time',
            'teacher_id' => ['nullable', Lookup::exists('teachers')], 'room_id' => ['nullable', Lookup::exists('rooms')],
        ]);
        $new = $this->schedule->reschedule($lesson, array_filter($d));

        return redirect()->route('lessons.show', $new)->with('ok', __('Занятие перенесено'));
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'group_id' => ['required', Lookup::exists('groups')], 'lesson_date' => 'required|date',
            'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i|after:start_time',
            'teacher_id' => ['nullable', Lookup::exists('teachers')], 'room_id' => ['nullable', Lookup::exists('rooms')],
        ]);
        $group = Group::findOrFail($d['group_id']);
        $d['teacher_id'] = $d['teacher_id'] ?? $group->teacher_id;
        $d['room_id'] = $d['room_id'] ?? $group->room_id;

        $conflicts = $this->schedule->lessonConflicts($d);
        if ($conflicts) {
            throw ValidationException::withMessages(['conflict' => array_column($conflicts, 'message')]);
        }
        $lesson = Lesson::create($d + ['branch_id' => $group->branch_id, 'status' => 'planned',
            'start_time' => ScheduleService::norm($d['start_time']), 'end_time' => ScheduleService::norm($d['end_time'])]);

        return redirect()->route('lessons.show', $lesson)->with('ok', __('Занятие добавлено'));
    }
}
