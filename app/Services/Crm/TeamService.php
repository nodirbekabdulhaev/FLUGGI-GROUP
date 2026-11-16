<?php

namespace App\Services\Crm;

use App\Exceptions\BusinessRule;
use App\Models\Team;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/** Отделы продаж: у каждого свой РОП (руководитель переводится в отдел). */
final class TeamService
{
    public function create(array $input): Team
    {
        $this->assertNameFree($input['name']);
        $headId = $input['head_id'] ?? null;

        return DB::transaction(function () use ($input, $headId) {
            if ($headId) {
                $this->assertRop($headId);
            }
            $team = Team::create(['name' => $input['name'], 'head_id' => $headId]);
            if ($headId) {
                User::whereKey($headId)->update(['team_id' => $team->id]);
            }
            Audit::log('team.create', 'team', $team->id, ['name' => ['old' => null, 'new' => $team->name], 'head_id' => ['old' => null, 'new' => $headId]]);

            return $team;
        });
    }

    public function update(Team $team, array $input): Team
    {
        if (isset($input['name']) && $input['name'] !== $team->name) {
            $this->assertNameFree($input['name']);
        }
        $headId = $input['head_id'] ?? null;

        return DB::transaction(function () use ($team, $input, $headId) {
            if ($headId) {
                $this->assertRop($headId);
            }
            $old = $team->getAttributes();
            $team->update(['name' => $input['name'] ?? $team->name, 'head_id' => $headId]);
            if ($headId) {
                User::whereKey($headId)->update(['team_id' => $team->id]);
            }
            $changes = Audit::diff($old, $team->fresh()->getAttributes(), ['name', 'head_id']);
            if ($changes) {
                Audit::log('team.update', 'team', $team->id, $changes);
            }

            return $team->refresh();
        });
    }

    /** Мягкое удаление: имя освобождается, история сохраняется. Отдел с сотрудниками удалить нельзя. */
    public function remove(Team $team): void
    {
        if (User::where('team_id', $team->id)->exists()) {
            throw new BusinessRule(t('crm.teams.hasMembers'));
        }
        DB::transaction(function () use ($team) {
            $name = $team->name;
            $team->update(['name' => mb_substr($name.' (удалён '.substr($team->id, 0, 8).')', 0, 191), 'head_id' => null]);
            $team->delete();
            Audit::log('team.delete', 'team', $team->id, ['name' => ['old' => $name, 'new' => null]]);
        });
    }

    private function assertNameFree(string $name): void
    {
        if (Team::withTrashed()->where('name', $name)->exists()) {
            throw new BusinessRule(t('crm.teams.nameTaken'), ['name' => t('crm.teams.nameTaken')]);
        }
    }

    private function assertRop(string $userId): void
    {
        $user = User::with('role')->where('status', 'ACTIVE')->find($userId);
        if (! $user || $user->role->code !== 'ROP') {
            throw new BusinessRule(t('crm.teams.headRop'), ['head_id' => t('crm.teams.headRopField')]);
        }
    }
}
