<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Services\LeadService;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function __construct(protected LeadService $leads) {}

    protected function fields(): array
    {
        return [
            ['name' => 'first_name', 'label' => 'Имя', 'rules' => 'required|string|max:100'],
            ['name' => 'last_name', 'label' => 'Фамилия', 'rules' => 'nullable|string|max:100'],
            ['name' => 'phone', 'label' => 'Телефон', 'type' => 'tel', 'rules' => 'required|string|max:32|regex:/^[+\d\s\-()]{7,20}$/', 'placeholder' => '+998 90 123 45 67'],
            ['name' => 'telegram', 'label' => 'Telegram', 'rules' => 'nullable|string|max:100'],
            ['name' => 'instagram', 'label' => 'Instagram username', 'rules' => 'nullable|string|max:100'],
            ['name' => 'age', 'label' => 'Возраст', 'type' => 'number', 'rules' => 'nullable|integer|min:3|max:99'],
            ['name' => 'parent_name', 'label' => 'Имя родителя', 'rules' => 'nullable|string|max:150'],
            ['name' => 'parent_phone', 'label' => 'Телефон родителя', 'type' => 'tel', 'rules' => 'nullable|string|max:32'],
            ['name' => 'source_id', 'label' => 'Источник', 'type' => 'select', 'options' => fn () => Lookup::sources(), 'rules' => ['required', Lookup::exists('lead_sources')]],
            ['name' => 'course_id', 'label' => 'Интересующий курс', 'type' => 'select', 'options' => fn () => Lookup::courses(), 'rules' => ['required', Lookup::exists('courses')]],
            ['name' => 'branch_id', 'label' => 'Филиал', 'type' => 'select', 'options' => fn () => Lookup::branches(), 'blank' => false, 'rules' => ['required', Lookup::exists('branches')]],
            ['name' => 'manager_id', 'label' => 'Ответственный менеджер', 'type' => 'select', 'options' => fn () => Lookup::managers(), 'rules' => ['required', Lookup::exists('users')]],
            ['name' => 'status_id', 'label' => 'Статус', 'type' => 'select', 'options' => fn () => Lookup::statuses(), 'blank' => false, 'rules' => ['required', Lookup::exists('lead_statuses')]],
            ['name' => 'next_contact_at', 'label' => 'Следующий контакт', 'type' => 'datetime-local', 'rules' => 'nullable|date'],
            ['name' => 'campaign', 'label' => 'Рекламная кампания', 'rules' => 'nullable|string|max:150'],
            ['name' => 'utm_source', 'label' => 'UTM source', 'rules' => 'nullable|string|max:150'],
            ['name' => 'utm_medium', 'label' => 'UTM medium', 'rules' => 'nullable|string|max:150'],
            ['name' => 'utm_campaign', 'label' => 'UTM campaign', 'rules' => 'nullable|string|max:150'],
            ['name' => 'utm_content', 'label' => 'UTM content', 'rules' => 'nullable|string|max:150'],
            ['name' => 'utm_term', 'label' => 'UTM term', 'rules' => 'nullable|string|max:150'],
            ['name' => 'comment', 'label' => 'Комментарий', 'type' => 'textarea', 'rules' => 'nullable|string|max:2000', 'span' => 'sm:col-span-2'],
        ];
    }

    protected function validated(Request $request): array
    {
        return $request->validate(collect($this->fields())->pluck('rules', 'name')->all());
    }

    public function index(Request $request)
    {
        $q = Lead::visibleTo($request->user())->with(['source:id,name', 'course:id,name', 'manager:id,name', 'status:id,name,color', 'branch:id,name']);

        if ($term = trim((string) $request->query('q'))) {
            $digits = preg_replace('/\D+/', '', $term);
            $q->where(function ($w) use ($term, $digits) {
                $w->where('first_name', 'like', "%$term%")->orWhere('last_name', 'like', "%$term%")
                    ->orWhere('telegram', 'like', "%$term%")->orWhere('instagram', 'like', "%$term%");
                if (strlen($digits) >= 3) {
                    $w->orWhere('phone', 'like', "%$digits%");
                }
                if (ctype_digit(ltrim($term, '#'))) {
                    $w->orWhere('id', (int) ltrim($term, '#'));
                }
            });
        }
        foreach (['status_id', 'source_id', 'manager_id', 'course_id'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->query($f));
            }
        }
        if ($request->filled('from')) {
            $q->whereDate('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('created_at', '<=', $request->query('to'));
        }
        if ($request->boolean('overdue')) {
            $q->whereNotNull('next_contact_at')->where('next_contact_at', '<', now())->whereNull('converted_student_id');
        }

        $sorts = ['created_at', 'first_name', 'next_contact_at', 'last_contact_at', 'id'];
        $sort = in_array($request->query('sort'), $sorts, true) ? $request->query('sort') : 'created_at';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        if ($request->query('export') === 'csv') {
            return app(\App\Services\ExportService::class)->csv('leads', ['ID', 'Имя', 'Телефон', 'Источник', 'Курс', 'Филиал', 'Менеджер', 'Статус', 'Создан'],
                $q->orderBy($sort, $dir)->get()->map(fn ($l) => [$l->id, $l->full_name, $l->phone, $l->source?->name, $l->course?->name, $l->branch?->name, $l->manager?->name, $l->status?->name, $l->created_at->format('d.m.Y H:i')]));
        }

        return view('leads.index', [
            'leads' => $q->orderBy($sort, $dir)->paginate(25)->withQueryString(),
            'sort' => $sort, 'dir' => $dir,
        ]);
    }

    public function create()
    {
        return view('leads.form', ['lead' => null, 'fields' => $this->fields(), 'values' => [
            'branch_id' => Lookup::defaultBranch(),
            'manager_id' => auth()->id(),
            'status_id' => LeadStatus::where('slug', 'new')->value('id'),
            'phone' => request('phone'),
        ]]);
    }

    public function store(Request $request)
    {
        $lead = $this->leads->create($this->validated($request), $request->user()->id);

        return redirect()->route('leads.show', $lead)->with('ok', __('Лид создан'));
    }

    public function edit(Lead $lead)
    {
        $this->authorizeLead($lead);
        $values = $lead->only(array_column($this->fields(), 'name'));
        $values['next_contact_at'] = $lead->next_contact_at?->format('Y-m-d\TH:i');

        return view('leads.form', ['lead' => $lead, 'fields' => $this->fields(), 'values' => $values]);
    }

    public function update(Request $request, Lead $lead)
    {
        $this->authorizeLead($lead);
        $data = $this->validated($request);
        $newStatus = (int) $data['status_id'] !== (int) $lead->status_id ? LeadStatus::find($data['status_id']) : null;
        unset($data['status_id']);

        $lead->update($data);
        if ($newStatus) {
            $this->leads->changeStatus($lead, $newStatus);
        }

        return redirect()->route('leads.show', $lead)->with('ok', __('Сохранено'));
    }

    public function show(Request $request, Lead $lead)
    {
        $this->authorizeLead($lead);
        $lead->load(['source', 'course', 'manager', 'status', 'branch', 'student', 'notes.user']);

        return view('leads.show', [
            'lead' => $lead,
            'statuses' => LeadStatus::orderBy('sort')->get(),
            'groups' => \App\Models\Group::with('course:id,name,price')->whereIn('status', ['enrolling', 'active'])
                ->when($lead->branch_id, fn ($q) => $q->where('branch_id', $lead->branch_id))->orderBy('name')->get(),
        ]);
    }

    public function status(Request $request, Lead $lead)
    {
        $this->authorizeLead($lead);
        $data = $request->validate(['status_id' => ['required', Lookup::exists('lead_statuses')], 'comment' => 'nullable|string|max:500']);
        $this->leads->changeStatus($lead, LeadStatus::findOrFail($data['status_id']), $data['comment'] ?? null);

        return back()->with('ok', __('Статус обновлён'));
    }

    public function note(Request $request, Lead $lead)
    {
        $this->authorizeLead($lead);
        $data = $request->validate(['body' => 'required|string|max:2000', 'type' => 'required|in:note,call', 'next_contact_at' => 'nullable|date']);
        $this->leads->note($lead, $data['body'], $data['type']);
        if (! empty($data['next_contact_at'])) {
            $lead->update(['next_contact_at' => $data['next_contact_at']]);
        }

        return back()->with('ok', __('Запись добавлена'));
    }

    public function convert(Request $request, Lead $lead)
    {
        $this->authorizeLead($lead);
        $data = $request->validate([
            'group_id' => ['nullable', Lookup::exists('groups')],
            'price' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'birth_date' => 'nullable|date|before:today',
            'gender' => 'nullable|in:male,female',
            'relation' => 'nullable|string|max:32',
        ]);
        $student = $this->leads->convert($lead, array_filter($data, fn ($v) => $v !== null && $v !== ''));

        return redirect()->route('students.show', $student)->with('ok', __('Ученик создан из лида'));
    }

    public function destroy(Lead $lead)
    {
        $lead->delete();

        return redirect()->route('leads.index')->with('ok', __('Удалено'));
    }

    /** Kanban-style funnel: one column per status. */
    public function funnel(Request $request)
    {
        $statuses = LeadStatus::orderBy('sort')->get();
        $base = Lead::visibleTo($request->user())->whereNull('converted_student_id')
            ->when($request->filled('manager_id'), fn ($q) => $q->where('manager_id', $request->query('manager_id')));
        $counts = (clone $base)->selectRaw('status_id, COUNT(*) c')->groupBy('status_id')->pluck('c', 'status_id');

        $columns = $statuses->map(function ($s) use ($base) {
            return [
                'status' => $s,
                'leads' => (clone $base)->where('status_id', $s->id)->with('course:id,name', 'manager:id,name', 'source:id,name')->latest('id')->limit(25)->get(),
            ];
        });

        return view('leads.funnel', ['columns' => $columns, 'counts' => $counts, 'statuses' => $statuses]);
    }

    protected function authorizeLead(Lead $lead): void
    {
        $u = auth()->user();
        abort_unless($u->hasPermission('leads.view_all') || $lead->manager_id === null || $lead->manager_id === $u->id, 403);
    }
}
