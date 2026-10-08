<?php

namespace App\Http\Controllers\Web;

use App\Models\Employee;
use App\Models\Expense;
use App\Support\Lookup;
use App\Support\Period;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ExpenseController extends CrudController
{
    protected function cfg(): array
    {
        return [
            'model' => Expense::class, 'route' => 'expenses', 'title' => 'Расходы', 'singular' => 'Расход', 'add' => 'Добавить расход',
            'perm' => ['view' => 'expenses.view', 'manage' => 'expenses.manage', 'delete' => 'expenses.manage'],
            'search' => ['description'], 'with' => ['category:id,name', 'branch:id,name', 'method:id,name', 'employee:id,full_name'], 'order' => ['spent_at', 'desc'], 'export' => true,
            'filters' => [
                ['name' => 'category_id', 'label' => 'Категория', 'options' => fn () => Lookup::categories()],
                ['name' => 'method_id', 'label' => 'Способ', 'options' => fn () => Lookup::methods()],
            ],
            'columns' => [
                ['label' => 'Дата', 'key' => 'spent_at', 'type' => 'date'],
                ['label' => 'Категория', 'key' => 'category.name'],
                ['label' => 'Сумма', 'key' => 'amount', 'type' => 'money'],
                ['label' => 'Филиал', 'key' => 'branch.name'],
                ['label' => 'Описание', 'key' => 'description'],
                ['label' => 'Сотрудник', 'key' => 'employee.full_name'],
                ['label' => 'Способ', 'key' => 'method.name'],
                ['label' => 'Файл', 'key' => 'file_path', 'type' => 'file'],
            ],
            'fields' => [
                ['name' => 'spent_at', 'label' => 'Дата', 'type' => 'date', 'default' => fn () => today()->toDateString(), 'rules' => 'required|date'],
                ['name' => 'category_id', 'label' => 'Категория', 'type' => 'select', 'blank' => false, 'options' => fn () => Lookup::categories(), 'rules' => ['required', Lookup::exists('expense_categories')]],
                ['name' => 'amount', 'label' => 'Сумма (UZS)', 'type' => 'money', 'rules' => 'required|numeric|min:1|max:999999999999'],
                ['name' => 'branch_id', 'label' => 'Филиал', 'type' => 'select', 'default' => fn () => Lookup::defaultBranch(), 'options' => fn () => Lookup::branches(), 'rules' => ['nullable', Lookup::exists('branches')]],
                ['name' => 'method_id', 'label' => 'Способ оплаты', 'type' => 'select', 'options' => fn () => Lookup::methods(), 'rules' => ['nullable', Lookup::exists('payment_methods')]],
                ['name' => 'employee_id', 'label' => 'Сотрудник', 'type' => 'select', 'options' => fn () => Employee::orderBy('full_name')->pluck('full_name', 'id')->all(), 'rules' => ['nullable', Lookup::exists('employees')]],
                ['name' => 'description', 'label' => 'Описание', 'rules' => 'nullable|string|max:255', 'span' => 'sm:col-span-2'],
                ['name' => 'file', 'column' => 'file_path', 'folder' => 'expenses', 'label' => 'Документ / чек (jpg, png, webp, pdf)', 'type' => 'file', 'span' => 'sm:col-span-2'],
            ],
        ];
    }

    protected function scope($q, Request $request)
    {
        [$from, $to] = Period::resolve($request->query('period', 'month'), $request->query('from'), $request->query('to'));
        if ($request->filled('from') || $request->filled('period')) {
            $q->whereBetween('spent_at', [$from->toDateString(), $to->toDateString()]);
        }

        return $q;
    }

    protected function summary($q): array
    {
        $sum = (float) $q->reorder()->sum('amount');

        return ['Сумма расходов' => money($sum)];
    }

    protected function beforeSave(array $data, ?Model $model): array
    {
        if (! $model) {
            $data['created_by'] = auth()->id();
        }

        return $data;
    }

    protected function afterPersist(Model $model, array $data, Request $request): void
    {
        // link to the stored receipt is served via files.show (private disk)
    }
}
