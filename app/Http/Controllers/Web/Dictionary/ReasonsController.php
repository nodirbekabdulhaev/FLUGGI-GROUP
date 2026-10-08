<?php

namespace App\Http\Controllers\Web\Dictionary;

use App\Http\Controllers\Web\CrudController;
use App\Models\ExpulsionReason;


class ReasonsController extends CrudController
{
    protected function cfg(): array
    {
        return [
            'model' => ExpulsionReason::class, 'route' => 'dict_reasons', 'title' => 'Причины отчисления', 'singular' => 'Причина', 'add' => 'Добавить',
            'perm' => ['view' => 'settings.manage', 'manage' => 'settings.manage', 'delete' => 'settings.manage'],
            'search' => ['name'], 'order' => ['id', 'asc'],
            'columns' => [['label' => 'Название', 'key' => 'name', 'link' => true]],
            'fields' => [['name' => 'name', 'label' => 'Название', 'rules' => 'required|string|max:100']],
        ];
    }
}
