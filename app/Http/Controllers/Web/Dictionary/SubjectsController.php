<?php

namespace App\Http\Controllers\Web\Dictionary;

use App\Http\Controllers\Web\CrudController;
use App\Models\Subject;


class SubjectsController extends CrudController
{
    protected function cfg(): array
    {
        return [
            'model' => Subject::class, 'route' => 'dict_subjects', 'title' => 'Предметы', 'singular' => 'Предмет', 'add' => 'Добавить',
            'perm' => ['view' => 'settings.manage', 'manage' => 'settings.manage', 'delete' => 'settings.manage'],
            'search' => ['name'], 'order' => ['name', 'asc'],
            'columns' => [['label' => 'Название', 'key' => 'name', 'link' => true]],
            'fields' => [['name' => 'name', 'label' => 'Название', 'rules' => 'required|string|max:100']],
        ];
    }
}
