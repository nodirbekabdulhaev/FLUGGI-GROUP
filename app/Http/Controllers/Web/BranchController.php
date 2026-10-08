<?php

namespace App\Http\Controllers\Web;

use App\Models\Branch;

class BranchController extends CrudController
{
    protected function cfg(): array
    {
        return [
            'model' => Branch::class, 'route' => 'branches', 'title' => 'Филиалы', 'singular' => 'Филиал', 'add' => 'Добавить филиал',
            'perm' => ['view' => 'branches.view', 'manage' => 'branches.manage', 'delete' => 'branches.manage'],
            'search' => ['name', 'address'], 'order' => ['name', 'asc'],
            'columns' => [
                ['label' => 'Название', 'key' => 'name', 'link' => true],
                ['label' => 'Адрес', 'key' => 'address'],
                ['label' => 'Телефон', 'key' => 'phone'],
                ['label' => 'Руководитель', 'key' => 'manager_name'],
                ['label' => 'Статус', 'key' => 'status', 'badge' => ['active' => ['Активный', 'emerald'], 'inactive' => ['Закрыт', 'slate']]],
            ],
            'fields' => [
                ['name' => 'name', 'label' => 'Название', 'rules' => 'required|string|max:120'],
                ['name' => 'address', 'label' => 'Адрес', 'rules' => 'nullable|string|max:255'],
                ['name' => 'phone', 'label' => 'Телефон', 'type' => 'tel', 'rules' => 'nullable|string|max:32'],
                ['name' => 'manager_name', 'label' => 'Руководитель', 'rules' => 'nullable|string|max:150'],
                ['name' => 'status', 'label' => 'Статус', 'type' => 'select', 'blank' => false, 'default' => 'active', 'options' => ['active' => 'Активный', 'inactive' => 'Закрыт'], 'rules' => 'required|in:active,inactive'],
            ],
        ];
    }

    protected function cannotDelete($model): ?string
    {
        $used = \App\Models\Student::withoutGlobalScopes()->where('branch_id', $model->id)->exists()
            || \App\Models\Group::withoutGlobalScopes()->where('branch_id', $model->id)->exists();

        return $used ? __('В филиале есть ученики или группы — закройте его (статус «Закрыт»).') : null;
    }
}
