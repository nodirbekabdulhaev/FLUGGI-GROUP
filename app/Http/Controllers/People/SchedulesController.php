<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\People\PeopleService;
use App\Services\People\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Настройки → Рабочие графики (ТЗ §36). */
class SchedulesController extends Controller
{
    public function index(): View
    {
        return view('settings.schedules', [
            'schedules' => PeopleService::schedules(),
            'users' => User::where('status', 'ACTIVE')->orderBy('full_name')->pluck('full_name', 'id')->all(),
        ]);
    }

    private function input(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'role_code' => ['nullable', Rule::in(UserService::ROLES)],
            'start_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'end_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/', 'after:start_time'],
            'work_days' => ['required', 'array', 'min:1', 'max:7'],
            'work_days.*' => ['integer', 'min:1', 'max:7'],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:180'],
            'user_ids' => ['nullable', 'array', 'max:500'],
            'user_ids.*' => ['uuid'],
        ], ['end_time.after' => t('schedules.errors.endBeforeStart'), 'work_days.required' => t('schedules.errors.days')]);

        return ['role_code' => $data['role_code'] ?? null, 'user_ids' => array_values($data['user_ids'] ?? []), 'is_active' => $request->boolean('is_active'), 'grace_minutes' => (int) $data['grace_minutes']] + $data;
    }

    public function store(Request $request): RedirectResponse
    {
        PeopleService::saveSchedule(null, $this->input($request));

        return back()->with('ok', t('schedules.saved'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        PeopleService::saveSchedule($id, $this->input($request));

        return back()->with('ok', t('schedules.saved'));
    }
}
