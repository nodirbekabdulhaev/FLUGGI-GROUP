<?php

namespace App\Http\Controllers\Web\Dictionary;

use App\Http\Controllers\Web\CrudController;
use App\Models\LeadSource;


class SourcesController extends CrudController
{
    protected function cfg(): array
    {
        return [
            'model' => LeadSource::class, 'route' => 'dict_sources', 'title' => 'Источники лидов', 'singular' => 'Источник', 'add' => 'Добавить',
            'perm' => ['view' => 'settings.manage', 'manage' => 'settings.manage', 'delete' => 'settings.manage'],
            'search' => ['name'], 'order' => ['name', 'asc'],
            'columns' => [['label' => 'Название', 'key' => 'name', 'link' => true], ['label' => 'Активен', 'key' => 'is_active', 'type' => 'bool']],
            'fields' => [['name' => 'name', 'label' => 'Название', 'rules' => 'required|string|max:100'], ['name' => 'is_active', 'label' => 'Активен', 'type' => 'checkbox', 'default' => true, 'rules' => 'boolean']],
        ];
    }
}
