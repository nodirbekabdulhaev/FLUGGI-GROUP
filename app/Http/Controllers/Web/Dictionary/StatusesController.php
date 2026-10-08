<?php

namespace App\Http\Controllers\Web\Dictionary;

use App\Http\Controllers\Web\CrudController;
use App\Models\LeadStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StatusesController extends CrudController
{
    protected function cfg(): array
    {
        return [
            'model' => LeadStatus::class, 'route' => 'dict_statuses', 'title' => 'Статусы лидов', 'singular' => 'Статус', 'add' => 'Добавить статус',
            'perm' => ['view' => 'settings.manage', 'manage' => 'settings.manage', 'delete' => 'settings.manage'],
            'search' => ['name'], 'order' => ['sort', 'asc'],
            'columns' => [
                ['label' => '№', 'key' => 'sort'],
                ['label' => 'Название', 'key' => 'name', 'link' => true],
                ['label' => 'Этап воронки', 'value' => fn ($s) => __(LeadStatus::STAGES[$s->stage] ?? '')],
                ['label' => 'Отказ', 'key' => 'is_lost', 'type' => 'bool'],
            ],
            'fields' => [
                ['name' => 'name', 'label' => 'Название', 'rules' => 'required|string|max:60'],
                ['name' => 'stage', 'label' => 'Этап воронки', 'type' => 'select', 'blank' => false, 'default' => 1, 'options' => LeadStatus::STAGES, 'rules' => 'required|integer|between:0,5', 'hint' => 'Используется для расчёта конверсии (связались → записаны → пришли → купили)'],
                ['name' => 'color', 'label' => 'Цвет', 'type' => 'select', 'blank' => false, 'default' => 'slate', 'options' => array_combine(LeadStatus::COLORS, LeadStatus::COLORS), 'rules' => 'required|in:'.implode(',', LeadStatus::COLORS)],
                ['name' => 'sort', 'label' => 'Порядок', 'type' => 'number', 'default' => 50, 'rules' => 'required|integer|min:0|max:999'],
                ['name' => 'is_lost', 'label' => 'Это отказ', 'type' => 'checkbox', 'default' => false, 'rules' => 'boolean'],
            ],
        ];
    }

    protected function beforeSave(array $data, ?Model $model): array
    {
        if (! $model) {
            $data['slug'] = Str::slug($data['name'], '_') ?: 'status';
            $data['slug'] = substr($data['slug'], 0, 24).'_'.Str::lower(Str::random(4));
        }

        return $data;
    }

    protected function cannotDelete(Model $model): ?string
    {
        return in_array($model->slug, ['new', 'converted'], true) ? __('Системный статус нельзя удалить.') : null;
    }
}
