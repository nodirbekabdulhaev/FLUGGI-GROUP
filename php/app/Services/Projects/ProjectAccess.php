<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;

/**
 * Видимость проектов и задач (ТЗ §65).
 *  Проект: OWN — я менеджер/РОП проекта или участник команды; TEAM — проекты моих отделов
 *  и моих направлений (проект-менеджер видит, например, всю «Медиа»).
 *  Задача: OWN — я ответственный или автор, либо менеджер/РОП проекта; TEAM — задачи проектов отдела.
 * Участие в команде даёт только чтение: менять проект можно по праву project.update/assign.
 */
final class ProjectAccess
{
    public const ACTIVE = ['NEW', 'PLANNING', 'IN_PROGRESS', 'REVIEW', 'WAITING_CLIENT', 'PAUSED'];

    public const STATUSES = ['NEW', 'PLANNING', 'IN_PROGRESS', 'REVIEW', 'WAITING_CLIENT', 'PAUSED', 'COMPLETED', 'CANCELLED'];

    public const OPEN_TASK = ['TODO', 'IN_PROGRESS', 'REVIEW', 'BLOCKED'];

    public const TASK_STATUSES = ['TODO', 'IN_PROGRESS', 'REVIEW', 'DONE', 'BLOCKED', 'CANCELLED'];

    public const KANBAN = ['TODO', 'IN_PROGRESS', 'REVIEW', 'DONE'];

    public const PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'URGENT'];

    public const SPECIALTIES = ['SMM', 'DESIGNER', 'VIDEOGRAPHER', 'EDITOR', 'TARGETOLOGIST', 'DEVELOPER', 'PHOTOGRAPHER', 'COPYWRITER', 'MOBILOGRAPHER', 'BRANDFACE'];

    /** Проекты, на которые у пользователя есть право $code. */
    public static function projects(string $code = 'project.read', ?Builder $query = null): Builder
    {
        // Участник команды видит проект (но не управляет им)
        $member = in_array($code, ['project.read', 'task.read'], true);
        $lead = function ($q, string $uid) use ($member) {
            $q->where('projects.manager_id', $uid)->orWhere('projects.rop_id', $uid);
            if ($member) {
                $q->orWhereExists(fn ($s) => $s->selectRaw('1')->from('project_members as pm')
                    ->whereColumn('pm.project_id', 'projects.id')->where('pm.user_id', $uid)->where('pm.status', '<>', 'REMOVED'));
            }
        };

        return access()->restrict(
            $query ?? Project::query(),
            $code,
            own: fn ($q, $uid) => $lead($q, $uid),
            team: function ($q, $teamIds, $uid, $directionIds) use ($lead) {
                $q->whereIn('projects.team_id', $teamIds ?: ['-']);
                if ($directionIds) {
                    $q->orWhereIn('projects.direction_id', $directionIds);
                }
                $q->orWhere(fn ($q) => $lead($q, $uid));
            },
        );
    }

    /** Задачи, на которые у пользователя есть право $code (task.read | task.update). */
    public static function tasks(string $code = 'task.read'): Builder
    {
        $mine = fn ($q, string $uid) => $q->where('tasks.assignee_id', $uid)->orWhere('tasks.creator_id', $uid)
            ->orWhereIn('tasks.project_id', fn ($s) => $s->select('id')->from('projects')
                ->where(fn ($w) => $w->where('manager_id', $uid)->orWhere('rop_id', $uid)));

        return access()->restrict(
            Task::query()->whereHas('project'),
            $code,
            own: fn ($q, $uid) => $mine($q, $uid),
            team: function ($q, $teamIds, $uid, $directionIds) use ($mine) {
                $q->whereIn('tasks.project_id', fn ($s) => $s->select('id')->from('projects')
                    ->where(function ($w) use ($teamIds, $directionIds) {
                        $w->whereIn('team_id', $teamIds ?: ['-']);
                        if ($directionIds) {
                            $w->orWhereIn('direction_id', $directionIds);
                        }
                    }));
                $q->orWhere(fn ($q) => $mine($q, $uid));
            },
        );
    }

    /** Проект в зоне права или 404. */
    public static function project(string $id, string $code = 'project.read'): Project
    {
        return self::projects($code)->whereKey($id)->firstOrFail();
    }

    /** Есть ли право $code на этот проект (без исключения). */
    public static function can(string $projectId, string $code): bool
    {
        if (! access()->can($code)) {
            return false;
        }

        return self::projects($code)->whereKey($projectId)->exists();
    }

    /** Id проектов из списка, на которые есть право $code. */
    public static function allowedIds(array $projectIds, string $code): array
    {
        if (! access()->can($code) || ! $projectIds) {
            return [];
        }

        return self::projects($code)->whereIn('projects.id', $projectIds)->pluck('projects.id')->all();
    }

    public static function task(string $id, string $code = 'task.read'): Task
    {
        return self::tasks($code)->with('project')->whereKey($id)->firstOrFail();
    }
}
