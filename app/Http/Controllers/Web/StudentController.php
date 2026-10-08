<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Debt;
use App\Models\ExpulsionReason;
use App\Models\Group;
use App\Models\GroupStudent;
use App\Models\Guardian;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentEvent;
use App\Services\EnrollmentService;
use App\Services\ExportService;
use App\Support\Lookup;
use App\Support\Upload;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StudentController extends Controller
{
    public function __construct(protected EnrollmentService $enrollments) {}

    protected function fields(bool $edit): array
    {
        $f = [
            ['name' => 'first_name', 'label' => 'Имя', 'rules' => 'required|string|max:100'],
            ['name' => 'last_name', 'label' => 'Фамилия', 'rules' => 'nullable|string|max:100'],
            ['name' => 'birth_date', 'label' => 'Дата рождения', 'type' => 'date', 'rules' => 'nullable|date|before:today'],
            ['name' => 'gender', 'label' => 'Пол', 'type' => 'select', 'options' => ['male' => 'Мужской', 'female' => 'Женский'], 'rules' => 'nullable|in:male,female'],
            ['name' => 'phone', 'label' => 'Телефон', 'type' => 'tel', 'rules' => 'nullable|string|max:32|regex:/^[+\d\s\-()]{7,20}$/'],
            ['name' => 'telegram', 'label' => 'Telegram', 'rules' => 'nullable|string|max:100'],
            ['name' => 'address', 'label' => 'Адрес', 'rules' => 'nullable|string|max:255', 'span' => 'sm:col-span-2'],
            ['name' => 'branch_id', 'label' => 'Филиал', 'type' => 'select', 'options' => fn () => Lookup::branches(), 'blank' => false, 'rules' => ['required', Lookup::exists('branches')]],
            ['name' => 'source_id', 'label' => 'Источник', 'type' => 'select', 'options' => fn () => Lookup::sources(), 'rules' => ['nullable', Lookup::exists('lead_sources')]],
            ['name' => 'manager_id', 'label' => 'Менеджер', 'type' => 'select', 'options' => fn () => Lookup::users(), 'rules' => ['nullable', Lookup::exists('users')]],
            ['name' => 'registered_at', 'label' => 'Дата регистрации', 'type' => 'date', 'rules' => 'nullable|date'],
            ['name' => 'photo', 'label' => 'Фото', 'type' => 'file', 'accept' => '.jpg,.jpeg,.png,.webp', 'rules' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:3072'],
            ['name' => 'notes', 'label' => 'Заметки', 'type' => 'textarea', 'rules' => 'nullable|string|max:2000', 'span' => 'sm:col-span-2'],
        ];
        if (! $edit) {
            array_push($f,
                ['name' => 'parent_name', 'label' => 'Родитель: ФИО', 'rules' => 'nullable|string|max:150'],
                ['name' => 'parent_phone', 'label' => 'Родитель: телефон', 'type' => 'tel', 'rules' => 'nullable|string|max:32'],
                ['name' => 'relation', 'label' => 'Степень родства', 'rules' => 'nullable|string|max:32'],
                ['name' => 'group_id', 'label' => 'Сразу записать в группу', 'type' => 'select', 'options' => fn () => Lookup::groups(), 'rules' => ['nullable', Lookup::exists('groups')]],
            );
        }

        return $f;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        // Two-phase listing: filter/sort/paginate a light query first, then compute balance and
        // attendance for the 25 visible rows only (correlated sub-selects on 10k+ rows are slow).
        $q = Student::visibleTo($user);

        if ($term = trim((string) $request->query('q'))) {
            $digits = preg_replace('/\D+/', '', $term);
            $q->where(function ($w) use ($term, $digits) {
                foreach (preg_split('/\s+/', $term) as $word) {
                    $w->where(fn ($n) => $n->where('first_name', 'like', "%$word%")->orWhere('last_name', 'like', "%$word%"));
                }
                $w->orWhere('telegram', 'like', "%$term%");
                if (strlen($digits) >= 3) {
                    $w->orWhere('phone', 'like', "%$digits%");
                }
                if (ctype_digit(ltrim($term, '#'))) {
                    $w->orWhere('id', (int) ltrim($term, '#'));
                }
            });
        }
        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        } elseif (! $request->boolean('all')) {
            $q->where('status', '!=', 'archived');
        }
        if ($request->filled('group_id')) {
            $q->whereHas('enrollments', fn ($e) => $e->where('group_id', $request->query('group_id'))->whereIn('status', ['active', 'frozen']));
        }
        if ($request->filled('course_id')) {
            $q->whereHas('enrollments.group', fn ($g) => $g->where('course_id', $request->query('course_id')));
        }
        if ($request->boolean('debtors')) {
            $q->whereIn('students.id', Debt::where('balance', '>', 0)->select('student_id'));
        }
        if ($request->filled('from')) {
            $q->whereDate('registered_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('registered_at', '<=', $request->query('to'));
        }

        $sorts = ['id', 'first_name', 'registered_at', 'status'];
        $sort = in_array($request->query('sort'), $sorts, true) ? $request->query('sort') : 'id';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $q->orderBy($sort, $dir);

        $detail = fn ($ids) => Student::withFinance()->addSelect([
            'att_total' => Attendance::selectRaw('COUNT(*)')->whereColumn('attendance.student_id', 'students.id'),
            'att_ok' => Attendance::selectRaw('COUNT(*)')->whereColumn('attendance.student_id', 'students.id')->whereIn('status', ['present', 'late']),
        ])->with(['guardians:id,full_name,phone', 'activeEnrollment.group:id,name,course_id', 'activeEnrollment.group.course:id,name', 'branch:id,name'])
            ->whereIn('students.id', $ids)->orderBy($sort, $dir)->get();

        if ($request->query('export') === 'csv') {
            Gate::authorize('students.view');

            return app(ExportService::class)->csv('students', ['ID', 'ФИО', 'Телефон', 'Родитель', 'Курс', 'Группа', 'Филиал', 'Баланс', 'Статус'],
                $detail((clone $q)->pluck('students.id'))->map(fn ($s) => [$s->id, $s->full_name, $s->phone, $s->guardians->first()?->full_name, $s->activeEnrollment?->group?->course?->name,
                    $s->activeEnrollment?->group?->name, $s->branch?->name, -$s->balance, Student::STATUSES[$s->status] ?? $s->status]));
        }

        $page = $q->select('students.id')->paginate(25)->withQueryString();
        $page->setCollection($detail($page->pluck('id')));

        return view('students.index', ['students' => $page, 'sort' => $sort, 'dir' => $dir]);
    }

    public function create()
    {
        return view('students.form', ['student' => null, 'fields' => $this->fields(false), 'values' => [
            'branch_id' => Lookup::defaultBranch(), 'registered_at' => today()->toDateString(), 'manager_id' => auth()->id(),
            'group_id' => request('group_id'),
        ]]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(collect($this->fields(false))->pluck('rules', 'name')->all());

        $student = DB::transaction(function () use ($data, $request) {
            $photo = $request->file('photo');
            $groupId = $data['group_id'] ?? null;
            $parent = Arr::only($data, ['parent_name', 'parent_phone', 'relation']);
            unset($data['photo'], $data['group_id'], $data['parent_name'], $data['parent_phone'], $data['relation']);

            $student = Student::create($data + ['status' => 'active', 'status_changed_at' => today()->toDateString()]);
            if ($photo) {
                $student->update(['photo_path' => Upload::store($photo, 'students', 'photo', ['jpg', 'png', 'webp'])]);
            }
            if (! empty($parent['parent_name']) || ! empty($parent['parent_phone'])) {
                $g = (! empty($parent['parent_phone']) ? Guardian::where('phone', Guardian::normalizePhone($parent['parent_phone']))->first() : null)
                    ?? Guardian::create(['full_name' => $parent['parent_name'] ?: 'Родитель', 'phone' => $parent['parent_phone'] ?? null]);
                $student->guardians()->attach($g->id, ['relation' => $parent['relation'] ?? null, 'is_primary' => true]);
            }
            StudentEvent::log($student, 'created', 'Ученик зарегистрирован');
            if ($groupId) {
                $this->enrollments->enroll($student, Group::findOrFail($groupId));
            }

            return $student;
        });

        return redirect()->route('students.show', $student)->with('ok', __('Ученик создан'));
    }

    public function edit(Student $student)
    {
        $values = $student->only(array_column($this->fields(true), 'name'));
        $values['birth_date'] = $student->birth_date?->toDateString();
        $values['registered_at'] = $student->registered_at?->toDateString();

        return view('students.form', ['student' => $student, 'fields' => $this->fields(true), 'values' => $values]);
    }

    public function update(Request $request, Student $student)
    {
        $data = $request->validate(collect($this->fields(true))->pluck('rules', 'name')->all());
        unset($data['photo']);
        if ($request->hasFile('photo')) {
            Upload::delete($student->photo_path);
            $data['photo_path'] = Upload::store($request->file('photo'), 'students', 'photo', ['jpg', 'png', 'webp']);
        }
        $student->update($data);

        return redirect()->route('students.show', $student)->with('ok', __('Сохранено'));
    }

    public function show(Request $request, Student $student)
    {
        $user = $request->user();
        abort_unless(Gate::allows('students.view') || ($user->restrictedToOwnGroups() && Student::visibleTo($user)->whereKey($student->id)->exists()), 403);

        $student->load(['guardians', 'source', 'manager', 'branch', 'expulsionReason', 'lead:id,first_name,last_name', 'telegramAccount']);
        $enrollments = $student->enrollments()->with(['group.course:id,name,duration_months', 'group.teacher:id,full_name', 'debt'])->orderByDesc('id')->get();
        $canFinance = Gate::any(['payments.view', 'debts.view']);

        return view('students.show', [
            'student' => $student,
            'enrollments' => $enrollments,
            'finance' => $canFinance ? $student->financeSummary() : null,
            'payments' => $canFinance ? $student->payments()->with('method:id,name', 'cashier:id,name', 'group:id,name')->latest('paid_at')->limit(15)->get() : collect(),
            'att' => $student->attendanceStats(),
            'recentAtt' => $student->attendances()->with('lesson.group:id,name')->latest('id')->limit(12)->get(),
            'events' => $student->events()->with('user:id,name')->limit(40)->get(),
            'reasons' => ExpulsionReason::orderBy('id')->get(),
            'groupsOpen' => Group::with('course:id,name')->whereIn('status', ['enrolling', 'active'])->orderBy('name')->get(),
            'allGuardians' => Gate::allows('students.manage') ? Guardian::orderBy('full_name')->limit(500)->get(['id', 'full_name', 'phone']) : collect(),
            'canFinance' => $canFinance,
        ]);
    }

    public function status(Request $request, Student $student)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Student::STATUSES))],
            'expulsion_reason_id' => ['nullable', Lookup::exists('expulsion_reasons')],
        ]);
        if ($data['status'] === 'expelled' && empty($data['expulsion_reason_id'])) {
            return back()->withErrors(['expulsion_reason_id' => 'Укажите причину отчисления.']);
        }

        DB::transaction(function () use ($student, $data) {
            $student->update([
                'status' => $data['status'],
                'status_changed_at' => today()->toDateString(),
                'expulsion_reason_id' => $data['status'] === 'expelled' ? $data['expulsion_reason_id'] : null,
            ]);
            // leaving / finishing closes current group memberships
            if (in_array($data['status'], ['expelled', 'completed', 'archived'], true)) {
                $student->enrollments()->whereIn('status', ['active', 'frozen'])->get()->each(
                    fn (GroupStudent $gs) => $data['status'] === 'completed' ? $this->enrollments->complete($gs) : $this->enrollments->leave($gs)
                );
                $student->update(['status' => $data['status']]);
            }
            StudentEvent::log($student, 'status', 'Статус: '.Student::STATUSES[$data['status']]);
        });

        return back()->with('ok', __('Статус обновлён'));
    }

    public function attachGuardian(Request $request, Student $student)
    {
        $data = $request->validate([
            'guardian_id' => ['nullable', Lookup::exists('parents')],
            'full_name' => 'nullable|required_without:guardian_id|string|max:150',
            'phone' => 'nullable|string|max:32',
            'telegram' => 'nullable|string|max:100',
            'relation' => 'nullable|string|max:32',
        ]);
        $guardian = ! empty($data['guardian_id']) ? Guardian::findOrFail($data['guardian_id'])
            : Guardian::create(['full_name' => $data['full_name'], 'phone' => $data['phone'] ?? null, 'telegram' => $data['telegram'] ?? null]);

        $student->guardians()->syncWithoutDetaching([$guardian->id => ['relation' => $data['relation'] ?? null, 'is_primary' => $student->guardians()->count() === 0]]);
        StudentEvent::log($student, 'guardian', 'Добавлен родитель: '.$guardian->full_name);

        return back()->with('ok', __('Родитель добавлен'));
    }

    public function detachGuardian(Student $student, int $guardian)
    {
        $student->guardians()->detach($guardian);

        return back()->with('ok', __('Родитель отвязан'));
    }

    public function destroy(Student $student)
    {
        $student->delete();   // soft delete; payments & history are kept

        return redirect()->route('students.index')->with('ok', __('Ученик перемещён в архив удалённых'));
    }
}
