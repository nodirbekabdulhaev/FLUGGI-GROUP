<?php

namespace App\Http\Controllers\Web\Dictionary;

use App\Http\Controllers\Web\CrudController;
use App\Models\ExpenseCategory;


class CategoriesController extends CrudController
{
    protected function cfg(): array
    {
        return [
            'model' => ExpenseCategory::class, 'route' => 'dict_categories', 'title' => 'Категории расходов', 'singular' => 'Категория', 'add' => 'Добавить',
            'perm' => ['view' => 'settings.manage', 'manage' => 'settings.manage', 'delete' => 'settings.manage'],
            'search' => ['name'], 'order' => ['name', 'asc'],
            'columns' => [['label' => 'Название', 'key' => 'name', 'link' => true]],
            'fields' => [['name' => 'name', 'label' => 'Название', 'rules' => 'required|string|max:100']],
        ];
    }
}
