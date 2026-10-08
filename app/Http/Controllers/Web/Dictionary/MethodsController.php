<?php

namespace App\Http\Controllers\Web\Dictionary;

use App\Http\Controllers\Web\CrudController;
use App\Models\PaymentMethod;


class MethodsController extends CrudController
{
    protected function cfg(): array
    {
        return [
            'model' => PaymentMethod::class, 'route' => 'dict_methods', 'title' => 'Способы оплаты', 'singular' => 'Способ оплаты', 'add' => 'Добавить',
            'perm' => ['view' => 'settings.manage', 'manage' => 'settings.manage', 'delete' => 'settings.manage'],
            'search' => ['name'], 'order' => ['id', 'asc'],
            'columns' => [['label' => 'Название', 'key' => 'name', 'link' => true], ['label' => 'Код', 'key' => 'code'], ['label' => 'Активен', 'key' => 'is_active', 'type' => 'bool']],
            'fields' => [['name' => 'name', 'label' => 'Название', 'rules' => 'required|string|max:60'], ['name' => 'code', 'label' => 'Код (латиницей)', 'rules' => ['required', 'alpha_dash', 'max:32']], ['name' => 'is_active', 'label' => 'Активен', 'type' => 'checkbox', 'default' => true, 'rules' => 'boolean']],
        ];
    }
}
