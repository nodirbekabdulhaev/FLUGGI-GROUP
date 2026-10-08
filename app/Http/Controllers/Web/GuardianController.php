<?php

namespace App\Http\Controllers\Web;

use App\Models\Guardian;
use Illuminate\Http\Request;

class GuardianController extends CrudController
{
    protected function cfg(): array
    {
        return [
            'model' => Guardian::class,
            'route' => 'guardians',
            'title' => 'Родители', 'singular' => 'Родитель', 'add' => 'Добавить родителя',
            'perm' => ['view' => 'students.view', 'manage' => 'students.manage', 'delete' => 'students.delete'],
            'show' => true,
            'search' => ['full_name', 'phone', 'telegram'],
            'with' => ['children:id,first_name,last_name'],
            'order' => ['full_name', 'asc'],
            'columns' => [
                ['label' => 'ФИО', 'key' => 'full_name', 'link' => true],
                ['label' => 'Телефон', 'key' => 'phone'],
                ['label' => 'Telegram', 'key' => 'telegram'],
                ['label' => 'Дети', 'value' => fn ($g) => $g->children->map->full_name->join(', ')],
            ],
            'fields' => [
                ['name' => 'full_name', 'label' => 'ФИО', 'rules' => 'required|string|max:150'],
                ['name' => 'phone', 'label' => 'Телефон', 'type' => 'tel', 'rules' => 'nullable|string|max:32'],
                ['name' => 'telegram', 'label' => 'Telegram', 'rules' => 'nullable|string|max:100'],
                ['name' => 'address', 'label' => 'Адрес', 'rules' => 'nullable|string|max:255'],
            ],
        ];
    }

    public function show(Request $request, string $id)
    {
        $this->can('view');
        $guardian = Guardian::with(['children' => fn ($q) => $q->withFinance(), 'telegramAccount'])->findOrFail($id);

        return view('guardians.show', ['guardian' => $guardian]);
    }

    protected function afterSave($model): string
    {
        return route('guardians.show', $model->id);
    }
}
