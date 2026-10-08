<?php

namespace App\Http\Controllers\Web;

use App\Models\Course;
use App\Support\Lookup;

class CourseController extends CrudController
{
    protected function cfg(): array
    {
        return [
            'model' => Course::class, 'route' => 'courses', 'title' => 'Курсы', 'singular' => 'Курс', 'add' => 'Добавить курс',
            'perm' => ['view' => 'courses.view', 'manage' => 'courses.manage', 'delete' => 'courses.manage'],
            'search' => ['name'], 'with' => ['subject:id,name'], 'order' => ['name', 'asc'],
            'filters' => [['name' => 'status', 'label' => 'Статус', 'options' => ['active' => 'Активный', 'archived' => 'Архив']]],
            'columns' => [
                ['label' => 'Название', 'key' => 'name', 'link' => true],
                ['label' => 'Предмет', 'key' => 'subject.name'],
                ['label' => 'Длительность', 'value' => fn ($c) => $c->duration_months.' '.__('мес.')],
                ['label' => 'Занятий', 'key' => 'lessons_count'],
                ['label' => 'Цена', 'key' => 'price', 'type' => 'money'],
                ['label' => 'Статус', 'key' => 'status', 'badge' => ['active' => ['Активный', 'emerald'], 'archived' => ['Архив', 'slate']]],
            ],
            'fields' => [
                ['name' => 'name', 'label' => 'Название', 'rules' => 'required|string|max:150'],
                ['name' => 'subject_id', 'label' => 'Предмет', 'type' => 'select', 'options' => fn () => Lookup::subjects(), 'rules' => ['nullable', Lookup::exists('subjects')]],
                ['name' => 'duration_months', 'label' => 'Длительность (месяцев)', 'type' => 'number', 'default' => 3, 'rules' => 'required|integer|min:1|max:60'],
                ['name' => 'lessons_count', 'label' => 'Количество занятий', 'type' => 'number', 'default' => 36, 'rules' => 'required|integer|min:0|max:1000'],
                ['name' => 'price', 'label' => 'Стандартная цена (UZS)', 'type' => 'money', 'default' => 0, 'rules' => 'required|numeric|min:0'],
                ['name' => 'status', 'label' => 'Статус', 'type' => 'select', 'blank' => false, 'default' => 'active', 'options' => ['active' => 'Активный', 'archived' => 'Архив'], 'rules' => 'required|in:active,archived'],
                ['name' => 'branches', 'label' => 'Филиалы', 'type' => 'multiselect', 'relation' => 'branches', 'options' => fn () => Lookup::branches(), 'rules' => 'nullable|array', 'hint' => 'Пусто = доступен во всех филиалах'],
                ['name' => 'description', 'label' => 'Описание', 'type' => 'textarea', 'rules' => 'nullable|string|max:2000', 'span' => 'sm:col-span-2'],
            ],
        ];
    }

    protected function cannotDelete($model): ?string
    {
        return $model->groups()->exists() ? __('У курса есть группы — переведите его в архив.') : null;
    }
}
