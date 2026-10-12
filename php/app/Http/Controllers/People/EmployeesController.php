<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Models\Direction;
use App\Models\Team;
use App\Models\User;
use App\Services\People\UserService;
use App\Services\Projects\ProjectAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Команда: сотрудники, менеджеры, РОП, исполнители (ТЗ §4); управление учётными записями. */
class EmployeesController extends Controller
{
    public const ROLE_BY_SLUG = ['employees' => null, 'managers' => 'MANAGER', 'rop' => 'ROP', 'executors' => 'EXECUTOR'];

    public function index(Request $request, string $role): View
    {
        abort_unless(array_key_exists($role, self::ROLE_BY_SLUG), 404);
        $fixed = self::ROLE_BY_SLUG[$role];
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(UserService::ROLES)],
            'team' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'BLOCKED'])],
        ]);
        $access = access();
        $data = [
            'slug' => $role, 'fixedRole' => $fixed, 'titleKey' => ['MANAGER' => 'titleManagers', 'ROP' => 'titleRops', 'EXECUTOR' => 'titleExecutors'][$fixed] ?? 'title', 'filters' => $f,
            'users' => UserService::list(['q' => $f['q'] ?? null, 'roleCode' => $fixed ?? ($f['role'] ?? null), 'teamId' => $f['team'] ?? null, 'status' => $f['status'] ?? null]),
            'teams' => Team::orderBy('name')->pluck('name', 'id')->all(),
            'directions' => Direction::where('is_active', true)->orderBy('sort')->get(),
            'canManage' => $access->can('employee.manage', 'ALL'),
            'canRates' => $access->can('payroll.manage', 'ALL'),
        ];
        // Окна действий: правка, удаление (с открытой работой), ставки
        if ($data['canManage'] && ($id = $request->query('edit'))) {
            $data['editing'] = UserService::findManageable($id);
        }
        if ($data['canManage'] && ($id = $request->query('delete'))) {
            $data['deleting'] = UserService::findManageable($id);
            $data['workload'] = UserService::workload($id);
            $data['transferTo'] = User::where('status', 'ACTIVE')->whereKeyNot($id)->with('role')->orderBy('full_name')->get();
        }

        // Сдельные ставки (модуль тарифов): CEO — любого сотрудника, остальные — свои
        if (($id = $request->query('rates')) && ($data['canRates'] || $id === $access->id())) {
            $data['ratesFor'] = User::findOrFail($id);
        }

        return view('people.employees', $data);
    }

    private function input(Request $request, bool $create): array
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'email' => [$create ? 'required' : 'sometimes', 'email', 'max:254'],
            'full_name' => [$create ? 'required' : 'sometimes', 'string', 'min:2', 'max:120'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9\s\-()]{7,20}$/'],
            'role_code' => [$create ? 'required' : 'sometimes', Rule::in(UserService::ROLES)],
            'team_id' => ['nullable', 'uuid'],
            'position' => ['nullable', 'string', 'max:120'],
            'specialty' => ['nullable', Rule::in(ProjectAccess::SPECIALTIES)],
            'locale' => ['nullable', Rule::in(['ru', 'uz'])],
            'direction_ids' => ['nullable', 'array', 'max:20'],
            'direction_ids.*' => ['uuid'],
        ]);
        $role = $data['role_code'] ?? null;
        $data['phone'] = ($data['phone'] ?? null) ?: null;
        $data['position'] = ($data['position'] ?? null) ?: null;
        // Отдел — только у отдела продаж; специализация — у исполнителя; направления — у проект-менеджера
        $data['team_id'] = in_array($role, UserService::SALES_ROLES, true) ? (($data['team_id'] ?? null) ?: null) : null;
        $data['specialty'] = $role === 'EXECUTOR' ? (($data['specialty'] ?? null) ?: null) : null;
        $data['direction_ids'] = $role === 'PROJECT_MANAGER' ? array_values($data['direction_ids'] ?? []) : [];
        if ($create) {
            $data['locale'] = $data['locale'] ?? 'ru';
        } else {
            unset($data['locale']);
        }

        return $data;
    }

    public function store(Request $request): RedirectResponse
    {
        $res = UserService::create($this->input($request, true));

        return back()->with('ok', t('employees.created'))
            ->with('tempPassword', ['name' => $res['user']->full_name, 'password' => $res['temporaryPassword']]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        UserService::update($id, $this->input($request, false));

        return redirect($this->listUrl($request))->with('ok', t('employees.updated'));
    }

    public function block(string $id): RedirectResponse
    {
        UserService::setBlocked($id, true);

        return back()->with('ok', t('employees.blocked'));
    }

    public function unblock(string $id): RedirectResponse
    {
        UserService::setBlocked($id, false);

        return back()->with('ok', t('employees.unblocked'));
    }

    public function resetPassword(string $id): RedirectResponse
    {
        $user = User::findOrFail($id);
        $password = UserService::resetPassword($id);

        return back()->with('tempPassword', ['name' => $user->full_name, 'password' => $password]);
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate(['transfer_to_id' => ['nullable', 'uuid']]);
        UserService::remove($id, $data['transfer_to_id'] ?? null);

        return redirect($this->listUrl($request))->with('ok', t('employees.deleted'));
    }

    private function listUrl(Request $request): string
    {
        $back = (string) $request->input('return_to');

        return str_starts_with($back, '/team/') ? $back : '/team/employees';
    }
}
