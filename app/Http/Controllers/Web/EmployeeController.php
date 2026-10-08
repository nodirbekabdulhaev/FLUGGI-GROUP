<?php

namespace App\Http\Controllers\Web;

use App\Models\Employee;
use App\Support\Lookup;
use Illuminate\Database\Eloquent\Model;

class EmployeeController extends CrudController
{
    protected function cfg(): array
    {
        return [
            'model' => Employee::class, 'route' => 'employees', 'title' => 'Сотрудники', 'singular' => 'Сотрудник', 'add' => 'Добавить сотрудника',
            'perm' => ['view' => 'employees.view', 'manage' => 'employees.manage', 'delete' => 'employees.manage'],
            'search' => ['full_name', 'phone', 'position'], 'with' => ['branch:id,name'], 'order' => ['full_name', 'asc'],
            'filters' => [['name' => 'status', 'label' => 'Статус', 'options' => ['active' => 'Работает', 'inactive' => 'Уволен']]],
            'columns' => [
                ['label' => 'ФИО', 'key' => 'full_name', 'link' => true],
                ['label' => 'Должность', 'key' => 'position'],
                ['label' => 'Телефон', 'key' => 'phone'],
                ['label' => 'Филиал', 'key' => 'branch.name'],
                ['label' => 'Оклад', 'key' => 'rate', 'type' => 'money'],
                ['label' => 'Статус', 'key' => 'status', 'badge' => ['active' => ['Работает', 'emerald'], 'inactive' => ['Уволен', 'slate']]],
            ],
            'fields' => [
                ['name' => 'full_name', 'label' => 'ФИО', 'rules' => 'required|string|max:150'],
                ['name' => 'position', 'label' => 'Должность', 'rules' => 'nullable|string|max:100'],
                ['name' => 'phone', 'label' => 'Телефон', 'type' => 'tel', 'rules' => 'nullable|string|max:32'],
                ['name' => 'telegram', 'label' => 'Telegram', 'rules' => 'nullable|string|max:100'],
                ['name' => 'branch_id', 'label' => 'Филиал', 'type' => 'select', 'options' => fn () => Lookup::branches(), 'rules' => ['nullable', Lookup::exists('branches')]],
                ['name' => 'user_id', 'label' => 'Учётная запись', 'type' => 'select', 'options' => fn () => Lookup::users(), 'rules' => ['nullable', Lookup::exists('users')]],
                ['name' => 'rate', 'label' => 'Оклад (UZS / месяц)', 'type' => 'money', 'default' => 0, 'rules' => 'required|numeric|min:0'],
                ['name' => 'hired_at', 'label' => 'Дата приёма', 'type' => 'date', 'rules' => 'nullable|date'],
                ['name' => 'status', 'label' => 'Статус', 'type' => 'select', 'blank' => false, 'default' => 'active', 'options' => ['active' => 'Работает', 'inactive' => 'Уволен'], 'rules' => 'required|in:active,inactive'],
            ],
        ];
    }

    protected function beforeSave(array $data, ?Model $model): array
    {
        return $data + ['pay_type' => 'fixed'];
    }
}
