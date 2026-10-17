<?php

namespace App\Http\Controllers\Crm;

use App\Models\Team;
use App\Models\User;
use App\Services\Crm\TeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Настройки → Отделы продаж. */
class TeamController extends CrmController
{
    public function __construct(private readonly TeamService $teams) {}

    public function index(): View
    {
        return view('crm.settings.teams', [
            'teams' => Team::with('head')->withCount('members')->orderBy('name')->get(),
            'rops' => User::where('status', 'ACTIVE')->whereHas('role', fn ($q) => $q->where('code', 'ROP'))
                ->orderBy('full_name')->pluck('full_name', 'id')->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $this->teams->create($this->input($request));

        return $this->done($request, t('settings.teamCreated'));
    }

    public function update(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $this->teams->update(Team::findOrFail($id), $this->input($request));

        return $this->done($request, t('settings.teamUpdated'));
    }

    public function destroy(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $this->teams->remove(Team::findOrFail($id));

        return $this->done($request, t('settings.teamDeleted'));
    }

    private function input(Request $request): array
    {
        return $this->check($request, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'head_id' => ['nullable', 'uuid'],
        ], ['name.required' => t('crm.teams.nameRequired'), 'name.min' => t('crm.teams.nameRequired')]) + ['head_id' => null];
    }
}
