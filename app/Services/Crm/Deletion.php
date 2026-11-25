<?php

namespace App\Services\Crm;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Удаление клиентов и проектов — только CEO (права client.delete / project.delete).
 * Удаление мягкое (deleted_at): записи пропадают из списков, но оплаты, договоры, комиссии,
 * журнал и таймлайн сохраняются и продолжают ссылаться на удалённую запись.
 */
final class Deletion
{
    /** Что будет удалено вместе с клиентом — для окна подтверждения. */
    public static function clientImpact(Client $client): array
    {
        return [
            'leads' => Lead::where('client_id', $client->id)->count(),
            'deals' => Deal::where('client_id', $client->id)->count(),
            'projects' => Project::where('client_id', $client->id)->count(),
        ];
    }

    /** Клиент вместе с его лидами, сделками и проектами (с задачами). */
    public static function client(Client $client): void
    {
        DB::transaction(function () use ($client) {
            $impact = self::clientImpact($client);
            foreach (Project::where('client_id', $client->id)->get() as $project) {
                self::softDeleteProject($project);
            }
            Deal::where('client_id', $client->id)->update(['deleted_at' => now()]);
            Lead::where('client_id', $client->id)->update(['deleted_at' => now()]);
            $client->delete();
            Audit::log('client.delete', 'client', $client->id, [
                'name' => ['old' => $client->name, 'new' => null],
                'cascade' => ['old' => null, 'new' => $impact],
            ]);
        });
    }

    /** Проект вместе с задачами. Сделка и оплаты остаются. */
    public static function project(Project $project): void
    {
        DB::transaction(function () use ($project) {
            $tasks = self::softDeleteProject($project);
            Audit::log('project.delete', 'project', $project->id, [
                'name' => ['old' => $project->name, 'new' => null],
                'tasks' => ['old' => $tasks, 'new' => 0],
            ]);
            Activities::log('project.deleted', ['project_id' => $project->id, 'deal_id' => $project->deal_id, 'client_id' => $project->client_id], ['name' => $project->name]);
        });
    }

    private static function softDeleteProject(Project $project): int
    {
        $tasks = Task::where('project_id', $project->id)->update(['deleted_at' => now()]);
        $project->delete();

        return $tasks;
    }
}
