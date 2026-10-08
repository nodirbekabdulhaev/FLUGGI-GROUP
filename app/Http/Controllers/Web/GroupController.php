<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Group;
use App\Models\GroupStudent;
use App\Models\Room;
use App\Models\Student;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class GroupController extends Controller
{
    protected function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Название', 'rules' => 'required|string|max:100'],
            ['name' => 'course_id', 'label' => 'Курс', 'type' => 'select', 'blank' => false, 'options' => fn () => Lookup::courses(), 'rules' => ['required', Lookup::exists('courses')]],
            ['name' => 'branch_id', 'label' => 'Филиал', 'type' => 'select', 'blank' => false, 'options' => fn () => Lookup::branches(), 'rules' => ['required', Lookup::exists('branches')]],
            ['name' => 'room_id', 'label' => 'Аудитория', 'type' => 'select', 'options' => fn () => Lookup::rooms(), 'rules' => ['nullable', Lookup::exists('rooms')]],
            ['name' => 'teacher_id', 'label' => 'Преподаватель', 'type' => 'select', 'options' => fn () => Lookup::teachers(), 'rules' => ['nullable', Lookup::exists('teachers')]],
            ['name' => 'max_students', 'label' => 'Макс. учеников', 'type' => 'number', 'rules' => 'required|integer|min:1|max:500'],
            ['name' => 'price', 'label' => 'Цена (пусто = цена курса)', 'type' => 'money', 'rules' => 'nullable|numeric|min:0'],
            ['name' => 'start_date', 'label' => 'Дата начала', 'type' => 'date', 'rules' => 'nullable|date'],
            ['name' => 'end_date', 'label' => 'Дата окончания', 'type' => 'date', 'rules' => 'nullable|date|after_or_equal:start_date'],
            ['name' => 'status', 'label' => 'Статус', 'type' => 'select', 'blank' => false, 'options' => Group::STATUSES, 'rules' => 'required|in:'.implode(',', array_keys(Group::STATUSES))],
        ];
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate(collect($this->fields())->pluck('rules', 'name')->all());
        if (! empty($data['room_id']) && Room::whereKey($data['room_id'])->value('branch_id') != $data['branch_id']) {
            throw ValidationException::withMessages(['room_id' => 'Аудитория принадлежит другому филиалу.']);
        }

        return $data;
    }

    public function index(Request $request)
    {
        $q = Group::visibleTo($request->user())->with(['course:id,name', 'teacher:id,full_name', 'room:id,name', 'branch:id,name'])
            ->withCount(['activeEnrollments as students_count']);
        if ($t = trim((string) $request->query('q'))) {
            $q->where('name', 'like', "%$t%");
        }
        foreach (['status', 'course_id', 'teacher_id'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->query($f));
            }
        }
        if (! $request->filled('status')) {
            $q->where('status', '!=', 'archived');
        }

        return view('groups.index', ['groups' => $q->orderBy('name')->paginate(25)->withQueryString()]);
    }

    public function create()
    {
        return view('groups.form', ['group' => null, 'fields' => $this->fields(), 'values' => [
            'branch_id' => Lookup::defaultBranch(), 'max_students' => 15, 'status' => 'enrolling', 'start_date' => today()->toDateString(),
        ]]);
    }

    public function store(Request $request)
    {
        $group = Group::create($this->validated($request));

        return redirect()->route('groups.show', $group)->with('ok', __('Группа создана'));
    }

    public function edit(Group $group)
    {
        $values = $group->only(array_column($this->fields(), 'name'));
        $values['start_date'] = $group->start_date?->toDateString();
        $values['end_date'] = $group->end_date?->toDateString();

        return view('groups.form', ['group' => $group, 'fields' => $this->fields(), 'values' => $values]);
    }

    public function update(Request $request, Group $group)
    {
        $group->update($this->validated($request));

        return redirect()->route('groups.show', $group)->with('ok', __('Сохранено'));
    }

    public function destroy(Group $group)
    {
        if ($group->activeEnrollments()->exists()) {
            return back()->withErrors(['delete' => 'В группе есть ученики — переведите или отчислите их, либо переведите группу в архив.']);
        }
        $group->delete();

        return redirect()->route('groups.index')->with('ok', __('Удалено'));
    }

    public function show(Request $request, int $group)
    {
        abort_unless(Gate::any(['groups.view', 'groups.view_own']), 403);
        $group = Group::visibleTo($request->user())->with(['course', 'teacher', 'room', 'branch'])->findOrFail($group);

        $showAll = $request->boolean('history');
        $enrollments = $group->enrollments()->with(['student' => fn ($q) => $q->withTrashed(), 'debt'])
            ->when(! $showAll, fn ($q) => $q->whereIn('status', ['active', 'frozen']))->orderBy('joined_at')->get();

        $att = Attendance::join('lessons', 'lessons.id', '=', 'attendance.lesson_id')->where('lessons.group_id', $group->id)
            ->selectRaw("COUNT(*) total, SUM(CASE WHEN attendance.status IN ('present','late') THEN 1 ELSE 0 END) ok, SUM(CASE WHEN attendance.status = 'absent' THEN 1 ELSE 0 END) absent")->first();
        $lessonStats = $group->lessons()->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');

        $canManage = Gate::allows('students.manage');
        $memberIds = $group->enrollments()->whereIn('status', ['active', 'frozen'])->pluck('student_id');

        return view('groups.show', [
            'group' => $group, 'enrollments' => $enrollments, 'showAll' => $showAll,
            'schedules' => $group->schedules()->where('is_active', true)->with(['teacher:id,full_name', 'room:id,name'])->orderBy('weekday')->orderBy('start_time')->get(),
            'upcoming' => $group->lessons()->where('lesson_date', '>=', today())->orderBy('lesson_date')->orderBy('start_time')->limit(6)->get(),
            'past' => $group->lessons()->where('lesson_date', '<', today())->orderByDesc('lesson_date')->limit(6)->get(),
            'attPercent' => $att->total ? round($att->ok * 100 / $att->total) : null,
            'lessonStats' => $lessonStats,
            'free' => max(0, $group->max_students - $memberIds->count()),
            'candidates' => $canManage ? Student::whereNotIn('id', $memberIds)->whereIn('status', ['active', 'frozen', 'completed'])->orderBy('first_name')->limit(500)->get(['id', 'first_name', 'last_name', 'phone']) : collect(),
            'canManage' => $canManage,
            'canFinance' => Gate::any(['payments.view', 'debts.view']),
        ]);
    }
}
