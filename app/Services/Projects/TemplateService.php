<?php

namespace App\Services\Projects;

use App\Models\ProjectTemplate;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Шаблоны проектов и задач (ТЗ §62): настраивает CEO/админ, применяет руководитель проекта. */
final class TemplateService
{
    public static function list(bool $activeOnly): Collection
    {
        return ProjectTemplate::with(['service', 'tasks' => fn ($q) => $q->orderBy('sort')])
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))->orderBy('name')->get();
    }

    private static function taskRows(array $tasks): array
    {
        return array_map(fn ($t, $sort) => [
            'title' => $t['title'],
            'description' => $t['description'] ?? null,
            'role' => ($t['role'] ?? null) ?: null,
            'start_offset_days' => (int) ($t['start_offset_days'] ?? 0),
            'duration_days' => (int) ($t['duration_days'] ?? 1),
            'priority' => $t['priority'] ?? 'MEDIUM',
            'sort' => $sort,
        ], array_values($tasks), array_keys(array_values($tasks)));
    }

    /** @param  array{name:string,service_id:?string,description:?string,is_active:bool,tasks:array}  $input */
    public static function save(?string $id, array $input): ProjectTemplate
    {
        $before = $id ? ProjectTemplate::findOrFail($id) : null;

        return DB::transaction(function () use ($before, $input) {
            $data = [
                'name' => $input['name'],
                'service_id' => $input['service_id'] ?: null,
                'description' => $input['description'] ?? null,
                'is_active' => (bool) $input['is_active'],
            ];
            // Изменение шаблона не затрагивает уже созданные задачи проектов
            if ($before) {
                $old = ['name' => $before->name, 'is_active' => $before->is_active];
                $before->tasks()->delete();
                $before->update($data);
                $t = $before;
            } else {
                $t = ProjectTemplate::create($data);
            }
            $t->tasks()->createMany(self::taskRows($input['tasks']));
            if (isset($old)) {
                Audit::log('project_template.update', 'project_template', $t->id, [
                    'name' => ['old' => $old['name'], 'new' => $t->name],
                    'isActive' => ['old' => $old['is_active'], 'new' => $t->is_active],
                    'tasks' => ['old' => null, 'new' => count($input['tasks'])],
                ]);
            } else {
                Audit::log('project_template.create', 'project_template', $t->id, ['name' => ['old' => null, 'new' => $t->name]]);
            }

            return $t;
        });
    }
}
