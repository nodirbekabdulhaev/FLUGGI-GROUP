<?php

namespace App\Http\Controllers\Web;

use App\Models\Teacher;
use App\Models\User;
use App\Services\ReportService;
use App\Support\Lookup;
use App\Support\Period;
use Illuminate\Http\Request;

class TeacherController extends CrudController
{
    protected function cfg(): array
    {
        return [
            'model' => Teacher::class, 'route' => 'teachers', 'title' => 'Преподаватели', 'singular' => 'Преподаватель', 'add' => 'Добавить преподавателя',
            'perm' => ['view' => 'teachers.view', 'manage' => 'teachers.manage', 'delete' => 'teachers.manage'],
            'show' => true, 'search' => ['full_name', 'phone', 'telegram'], 'with' => ['subject:id,name', 'branch:id,name'], 'order' => ['full_name', 'asc'],
            'filters' => [['name' => 'status', 'label' => 'Статус', 'options' => ['active' => 'Активный', 'vacation' => 'В отпуске', 'inactive' => 'Неактивный']]],
            'columns' => [
                ['label' => 'ФИО', 'key' => 'full_name', 'link' => true],
                ['label' => 'Телефон', 'key' => 'phone'],
                ['label' => 'Предмет', 'key' => 'subject.name'],
                ['label' => 'Филиал', 'key' => 'branch.name'],
                ['label' => 'Оплата', 'value' => fn ($t) => __(Teacher::PAY_TYPES[$t->pay_type] ?? '').': '.number_format($t->rate, 0, '.', ' ').($t->pay_type === 'percent' ? '%' : '')],
                ['label' => 'Статус', 'key' => 'status', 'badge' => ['active' => ['Активный', 'emerald'], 'vacation' => ['В отпуске', 'amber'], 'inactive' => ['Неактивный', 'slate']]],
            ],
            'fields' => [
                ['name' => 'full_name', 'label' => 'ФИО', 'rules' => 'required|string|max:150'],
                ['name' => 'phone', 'label' => 'Телефон', 'type' => 'tel', 'rules' => 'nullable|string|max:32'],
                ['name' => 'telegram', 'label' => 'Telegram', 'rules' => 'nullable|string|max:100'],
                ['name' => 'subject_id', 'label' => 'Предмет', 'type' => 'select', 'options' => fn () => Lookup::subjects(), 'rules' => ['nullable', Lookup::exists('subjects')]],
                ['name' => 'branch_id', 'label' => 'Филиал', 'type' => 'select', 'options' => fn () => Lookup::branches(), 'rules' => ['nullable', Lookup::exists('branches')]],
                ['name' => 'user_id', 'label' => 'Учётная запись для входа', 'type' => 'select', 'options' => fn () => Lookup::users(), 'rules' => ['nullable', Lookup::exists('users')], 'hint' => 'Преподаватель увидит только свои группы'],
                ['name' => 'pay_type', 'label' => 'Тип оплаты', 'type' => 'select', 'blank' => false, 'default' => 'fixed', 'options' => Teacher::PAY_TYPES, 'rules' => 'required|in:fixed,per_lesson,per_student,percent'],
                ['name' => 'rate', 'label' => 'Ставка (сумма или %)', 'type' => 'number', 'step' => '0.01', 'default' => 0, 'rules' => 'required|numeric|min:0|max:999999999'],
                ['name' => 'hired_at', 'label' => 'Дата начала работы', 'type' => 'date', 'rules' => 'nullable|date'],
                ['name' => 'status', 'label' => 'Статус', 'type' => 'select', 'blank' => false, 'default' => 'active', 'options' => ['active' => 'Активный', 'vacation' => 'В отпуске', 'inactive' => 'Неактивный'], 'rules' => 'required|in:active,vacation,inactive'],
            ],
        ];
    }

    public function show(Request $request, string $id, ReportService $reports)
    {
        $this->can('view');
        $teacher = Teacher::with(['subject', 'branch', 'user', 'telegramAccount'])->findOrFail($id);
        [$from, $to, $key] = Period::resolve($request->query('period'), $request->query('from'), $request->query('to'));
        $kpi = $reports->teachers($from, $to)->firstWhere('id', $teacher->id);
        $groups = $teacher->groups()->with('course:id,name')->withCount(['activeEnrollments as students_count'])->get();

        return view('teachers.show', compact('teacher', 'kpi', 'groups', 'from', 'to', 'key'));
    }

    protected function afterSave($model): string
    {
        return route('teachers.show', $model->id);
    }

    protected function cannotDelete($model): ?string
    {
        return $model->groups()->exists() ? __('У преподавателя есть группы — переведите его в «Неактивный».') : null;
    }
}
