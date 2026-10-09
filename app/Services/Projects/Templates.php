<?php

namespace App\Services\Projects;

use App\Domain\Tasks;
use App\Exceptions\BusinessRule;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\TaskStatusHistory;
use App\Services\Crm\Activities;
use App\Support\Format;

/** Шаблоны проектов (ТЗ §62, §77): задачи по услуге с ролями исполнителей и сроками. */
final class Templates
{
    public static function forService(?string $serviceId): ?ProjectTemplate
    {
        if (! $serviceId) {
            return null;
        }

        return ProjectTemplate::where('service_id', $serviceId)->where('is_active', true)->orderBy('name')->first();
    }

    /**
     * Создаёт задачи проекта из шаблона. Сроки — от даты начала проекта (или сегодня),
     * исполнитель — активный участник команды с ролью задачи, иначе РОП проекта
     * (задача перейдёт к исполнителю, когда его добавят в команду). Вызывать в транзакции.
     */
    public static function apply(Project $project, string $templateId, string $actorId): int
    {
        $template = ProjectTemplate::with(['tasks' => fn ($q) => $q->orderBy('sort')])->find($templateId);
        if (! $template || ! $template->is_active) {
            abort(404);
        }
        if ($template->tasks->isEmpty()) {
            throw new BusinessRule(t('templates.errors.noTasks'));
        }
        $start = $project->start_date?->format('Y-m-d') ?? Format::today()->toDateString();
        $byRole = ProjectMember::where('project_id', $project->id)->where('status', 'ACTIVE')->whereNotNull('role')
            ->orderBy('assigned_at')->get()->pluck('user_id', 'role'); // позже назначенный — приоритетнее
        $sortOrder = (float) (Task::where('project_id', $project->id)->where('status', 'TODO')->max('sort_order') ?? 0);

        foreach ($template->tasks as $t) {
            $dates = Tasks::templateTaskDates($start, (int) $t->start_offset_days, (int) $t->duration_days);
            $sortOrder += 1000;
            $task = Task::create([
                'project_id' => $project->id,
                'title' => $t->title,
                'description' => $t->description,
                'template_role' => $t->role,
                'assignee_id' => ($t->role ? ($byRole[$t->role] ?? null) : null) ?? $project->rop_id,
                'creator_id' => $actorId,
                'priority' => $t->priority ?? 'MEDIUM',
                'start_date' => $dates['startDate'],
                'deadline' => $dates['deadline'],
                'sort_order' => $sortOrder,
            ]);
            TaskStatusHistory::create(['task_id' => $task->id, 'to_status' => 'TODO', 'changed_by_id' => $actorId]);
        }
        $project->update([
            'template_id' => $project->template_id ?? $template->id,
            'start_date' => $project->start_date ?? $start,
        ]);
        Activities::log('project.template_applied', ['project_id' => $project->id], ['template' => $template->name, 'tasks' => $template->tasks->count()], $actorId);

        return $template->tasks->count();
    }
}
