<?php

namespace App\Http\Controllers\Web;

use App\Models\Room;
use App\Support\Lookup;

class RoomController extends CrudController
{
    protected function cfg(): array
    {
        return [
            'model' => Room::class, 'route' => 'rooms', 'title' => 'Аудитории', 'singular' => 'Аудитория', 'add' => 'Добавить аудиторию',
            'perm' => ['view' => 'branches.view', 'manage' => 'branches.manage', 'delete' => 'branches.manage'],
            'search' => ['name'], 'with' => ['branch:id,name'], 'order' => ['name', 'asc'],
            'columns' => [
                ['label' => 'Название', 'key' => 'name', 'link' => true],
                ['label' => 'Филиал', 'key' => 'branch.name'],
                ['label' => 'Этаж', 'key' => 'floor'],
                ['label' => 'Вместимость', 'key' => 'capacity'],
                ['label' => 'Оборудование', 'key' => 'equipment'],
                ['label' => 'Статус', 'key' => 'status', 'badge' => ['active' => ['Активна', 'emerald'], 'inactive' => ['Закрыта', 'slate']]],
            ],
            'fields' => [
                ['name' => 'name', 'label' => 'Название', 'rules' => 'required|string|max:100'],
                ['name' => 'branch_id', 'label' => 'Филиал', 'type' => 'select', 'blank' => false, 'default' => fn () => Lookup::defaultBranch(), 'options' => fn () => Lookup::branches(), 'rules' => ['required', Lookup::exists('branches')]],
                ['name' => 'floor', 'label' => 'Этаж', 'rules' => 'nullable|string|max:16'],
                ['name' => 'capacity', 'label' => 'Вместимость', 'type' => 'number', 'default' => 15, 'rules' => 'required|integer|min:0|max:1000'],
                ['name' => 'equipment', 'label' => 'Оборудование', 'rules' => 'nullable|string|max:255', 'hint' => 'Напр.: проектор, доска, кондиционер'],
                ['name' => 'status', 'label' => 'Статус', 'type' => 'select', 'blank' => false, 'default' => 'active', 'options' => ['active' => 'Активна', 'inactive' => 'Закрыта'], 'rules' => 'required|in:active,inactive'],
            ],
        ];
    }
}
