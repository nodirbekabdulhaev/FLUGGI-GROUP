<?php

namespace App\Services\Analytics;

use App\Auth\Access;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;

/**
 * Видимость проектов и задач для поиска и экспорта — те же правила, что в модуле «Проекты» (ТЗ §65):
 *  Проект: OWN — я менеджер/РОП проекта или участник команды; TEAM — проекты моих отделов и направлений.
 *  Задача: OWN — я ответственный или автор, либо менеджер/РОП проекта; TEAM — задачи проектов отдела.
 */
final class ProjectScope
{
    public static function projects(Access $a, string $code = 'project.read'): Builder
    {
        $q = Project::query();
        $scope = $a->scope($code);
        if ($scope === 'ALL') {
            return $q;
        }
        $me = $a->id();
        $member = fn ($w) => $w->orWhereHas('members', fn ($m) => $m->where('user_id', $me)->where('status', '<>', 'REMOVED'));

        return $q->where(function ($w) use ($scope, $a, $me, $member, $code) {
            $w->where('manager_id', $me)->orWhere('rop_id', $me);
            // Участник команды видит проект (но не управляет им и не видит финансы)
            if ($code === 'project.read' || $code === 'task.read') {
                $member($w);
            }
            if ($scope === 'TEAM') {
                $w->orWhereIn('team_id', $a->teamIds() ?: ['-']);
                if ($a->directionIds) {
                    $w->orWhereIn('direction_id', $a->directionIds);
                }
            }
        });
    }

    public static function tasks(Access $a, string $code = 'task.read'): Builder
    {
        $q = Task::query()->whereHas('project');
        $scope = $a->scope($code);
        if ($scope === 'ALL') {
            return $q;
        }
        $me = $a->id();

        return $q->where(function ($w) use ($scope, $a, $me) {
            $w->where('assignee_id', $me)->orWhere('creator_id', $me)
                ->orWhereHas('project', fn ($p) => $p->where('manager_id', $me)->orWhere('rop_id', $me));
            if ($scope === 'TEAM') {
                $w->orWhereHas('project', function ($p) use ($a) {
                    $p->whereIn('team_id', $a->teamIds() ?: ['-']);
                    if ($a->directionIds) {
                        $p->orWhereIn('direction_id', $a->directionIds);
                    }
                });
            }
        });
    }
}
