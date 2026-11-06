<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Services\People\RoleService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Настройки → Роли и права (ТЗ §3). */
class RolesController extends Controller
{
    public function index(Request $request): View
    {
        $roles = RoleService::list();
        $role = $roles->firstWhere('id', $request->query('role')) ?? $roles->first();

        return view('settings.roles', ['roles' => $roles, 'role' => $role, 'modules' => RoleService::modules()]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate(['permissions' => ['nullable', 'array'], 'permissions.*' => ['nullable', Rule::in(Permissions::SCOPES)]]);
        RoleService::updatePermissions($id, array_filter($data['permissions'] ?? []));

        return redirect('/settings/roles?role='.$id)->with('ok', t('settings.rolesSaved'));
    }
}
