<?php

namespace App\Services\Projects;

use App\Domain\Tasks;
use App\Exceptions\BusinessRule;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskStatusHistory;
use App\Services\Crm\Activities;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Задачи проектов (ТЗ §22–24): Kanban, статусы, переделки, комментарии. */
final class TaskService
{
    public const VIEWS = ['all', 'today', 'overdue', 'in_progress', 'review', 'done'];

    public static function code(Task $t): string
    {
        return Format::code('T', $t->number);
    }

    /**
     * @param  array{view?:string,projectId?:?string,assigneeId?:?string,mine?:bool,status?:?string,q?:?string}  $f
     */
    public static function list(array $f, int $perPage = 25): LengthAwarePaginator
    {
        $q = ProjectAccess::tasks()->with(['project:id,number,name,rop_id,manager_id,status', 'assignee:id,full_name', 'creator:id,full_name'])
            ->withCount('comments');
        if (! empty($f['projectId'])) {
            $q->where('tasks.project_id', $f['projectId']);
        }
        if (! empty($f['assigneeId'])) {
            $q->where('tasks.assignee_id', $f['assigneeId']);
        }
        if (! empty($f['mine'])) {
            $q->where('tasks.assignee_id', access()->id());
        }
        if (! empty($f['status'])) {
            $q->where('tasks.status', $f['status']);
        }
        if (! empty($f['q'])) {
            $q->where('tasks.title', 'like', '%'.$f['q'].'%');
        }
        $view = $f['view'] ?? 'all';
        switch ($view) {
            case 'today':
                // Сегодня: дедлайн до конца дня (включая просроченные) или задача начинается сегодня
                $today = Tasks::companyDate(now());
                $tomorrow = Tasks::companyDayStart($today)->addDay();
                $q->whereIn('tasks.status', ProjectAccess::OPEN_TASK)
                    ->where(fn ($w) => $w->where('tasks.deadline', '<', $tomorrow)->orWhere('tasks.start_date', $today));
                break;
            case 'overdue':
                ProjectService::overdueTasks($q);
                break;
            case 'in_progress':
                $q->where('tasks.status', 'IN_PROGRESS');
                break;
            case 'review':
                $q->where('tasks.status', 'REVIEW');
                break;
            case 'done':
                $q->where('tasks.status', 'DONE');
                break;
            default:
                if (empty($f['status']) && empty($f['projectId'])) {
                    $q->where('tasks.status', '<>', 'CANCELLED');
                }
        }
        if (! empty($f['projectId'])) {
            $q->orderBy('tasks.sort_order');
        } elseif ($view === 'done') {
            $q->orderByDesc('tasks.completed_at');
        } else {
            $q->orderByRaw('tasks.deadline IS NULL')->orderBy('tasks.deadline')->orderBy('tasks.created_at');
        }
        $page = $q->paginate($perPage)->withQueryString();
        self::decorate($page->getCollection());

        return $page;
    }

    /** can_edit, просрочка, «ждёт исполнителя» для списка задач. */
    public static function decorate(Collection $tasks): void
    {
        $managed = array_flip(ProjectAccess::allowedIds($tasks->pluck('project_id')->unique()->values()->all(), 'task.create'));
        $uid = access()->id();
        foreach ($tasks as $t) {
            $t->setAttribute('can_edit', isset($managed[$t->project_id]) || $t->creator_id === $uid);
            $t->setAttribute('overdue_days', Tasks::taskOverdueDays($t->deadline, in_array($t->status, ProjectAccess::OPEN_TASK, true), now()));
            $t->setAttribute('waiting_for_role', (bool) $t->template_role && $t->assignee_id === $t->project?->rop_id && $t->status === 'TODO');
        }
    }

    /** Карточка задачи: комментарии и история статусов. */
    public static function get(string $id): Task
    {
        $task = ProjectAccess::task($id);
        $task->load(['project:id,number,name,rop_id,manager_id,status', 'assignee:id,full_name', 'creator:id,full_name'])->loadCount('comments');
        self::decorate(collect([$task]));
        $task->setRelation('comments', TaskComment::with('author:id,full_name')->where('task_id', $id)->orderBy('created_at')->get());
        $task->setRelation('history', TaskStatusHistory::with('changedBy:id,full_name')->where('task_id', $id)->orderByDesc('created_at')->get());

        return $task;
    }

    private static function assertProjectOpen(Project $p): void
    {
        if (! in_array($p->status, ProjectAccess::ACTIVE, true)) {
            throw new BusinessRule(t('tasks.errors.projectClosed'));
        }
    }

    /** Ответственный — участник команды, РОП или менеджер проекта. */
    private static function assertAssignee(Project $p, ?string $userId): void
    {
        if (! $userId || $userId === $p->rop_id || $userId === $p->manager_id) {
            return;
        }
        if (! ProjectMember::where('project_id', $p->id)->where('user_id', $userId)->where('status', 'ACTIVE')->exists()) {
            throw new BusinessRule(t('tasks.errors.assigneeNotMember'), ['assignee_id' => t('tasks.errors.assigneeNotMember')]);
        }
    }

    /** @param  array<string,mixed>  $input  поля задачи; deadline — уже UTC */
    public static function create(array $input): Task
    {
        $access = access();
        $p = ProjectAccess::project($input['project_id'], 'task.create');
        self::assertProjectOpen($p);
        self::assertAssignee($p, $input['assignee_id']);

        return DB::transaction(function () use ($p, $input, $access) {
            $last = Task::where('project_id', $p->id)->where('status', 'TODO')->max('sort_order');
            $t = Task::create([
                'project_id' => $p->id,
                'title' => $input['title'],
                'description' => $input['description'] ?? null,
                'assignee_id' => $input['assignee_id'],
                'creator_id' => $access->id(),
                'priority' => $input['priority'] ?? 'MEDIUM',
                'start_date' => $input['start_date'] ?? null,
                'deadline' => $input['deadline'] ?? null,
                'sort_order' => Tasks::sortBetween($last === null ? null : (float) $last, null),
            ]);
            $t->refresh();
            TaskStatusHistory::create(['task_id' => $t->id, 'to_status' => 'TODO', 'changed_by_id' => $access->id()]);
            Activities::log('task.created', ['project_id' => $p->id, 'task_id' => $t->id], ['number' => self::code($t), 'title' => $t->title]);
            Audit::log('task.create', 'task', $t->id, ['title' => ['old' => null, 'new' => $t->title], 'assigneeId' => ['old' => null, 'new' => $t->assignee_id]]);
            if ($t->assignee_id !== $access->id()) {
                Outbox::publish('task.assigned', ['taskId' => $t->id, 'assigneeId' => $t->assignee_id]);
            }

            return $t;
        });
    }

    private static function canEdit(Task $t): bool
    {
        return $t->creator_id === access()->id() || ProjectAccess::can($t->project_id, 'task.create');
    }

    /**
     * Поля задачи меняет тот, кто управляет задачами проекта, или автор задачи.
     * Ответственный может менять только процент выполнения (и статус — через move).
     *
     * @param  array<string,mixed>  $input
     */
    public static function update(string $id, array $input): Task
    {
        $t = ProjectAccess::task($id, 'task.update');
        self::assertProjectOpen($t->project);
        $onlyProgress = array_keys($input) === ['progress_pct'];
        if (! self::canEdit($t) && ! ($onlyProgress && $t->assignee_id === access()->id())) {
            abort(403);
        }
        if (array_key_exists('assignee_id', $input)) {
            self::assertAssignee($t->project, $input['assignee_id']);
        }
        $fields = ['title', 'description', 'assignee_id', 'priority', 'start_date', 'deadline', 'progress_pct'];
        $data = array_intersect_key($input, array_flip($fields));
        $oldDeadline = $t->deadline ? CarbonImmutable::parse($t->deadline)->format('Y-m-d\TH:i:s.v\Z') : null;
        if (array_key_exists('deadline', $data)) {
            $data['deadline'] = $data['deadline'] ? CarbonImmutable::parse($data['deadline'])->utc() : null;
        }
        $newDeadline = array_key_exists('deadline', $data) ? $data['deadline']?->format('Y-m-d\TH:i:s.v\Z') : $oldDeadline;
        // Новый дедлайн — напоминания о просрочке начинаются заново
        if ($newDeadline !== $oldDeadline) {
            $data['overdue_notified_at'] = null;
        }

        DB::transaction(function () use ($t, $data, $fields, $id, $input, $oldDeadline) {
            $before = ['start_date' => $t->start_date?->format('Y-m-d'), 'deadline' => $oldDeadline] + $t->getAttributes();
            $compare = $data;
            if (array_key_exists('deadline', $compare)) {
                $compare['deadline'] = $compare['deadline']?->format('Y-m-d\TH:i:s.v\Z');
            }
            $changes = Audit::diff($before, $compare, $fields);
            $t->update($data);
            if (! $changes) {
                return;
            }
            Audit::log('task.update', 'task', $id, $changes);
            Activities::log('task.updated', ['project_id' => $t->project_id, 'task_id' => $id], ['number' => self::code($t), 'title' => $t->title, 'fields' => array_keys($changes)]);
            if (isset($changes['deadline'])) {
                Outbox::publish('task.deadline_changed', ['taskId' => $id]);
            }
            if (isset($changes['assignee_id']) && ! empty($input['assignee_id']) && $input['assignee_id'] !== access()->id()) {
                Outbox::publish('task.assigned', ['taskId' => $id, 'assigneeId' => $input['assignee_id']]);
            }
        });

        return $t->fresh();
    }

    /**
     * Перемещение карточки Kanban (ТЗ §23): статус + позиция в колонке.
     * Закрытую или отменённую задачу возвращает в работу только тот, кто управляет задачами.
     */
    public static function move(string $id, string $to, ?string $beforeId = null): Task
    {
        $t = ProjectAccess::task($id, 'task.update');
        self::assertProjectOpen($t->project);
        $from = $t->status;
        if (! self::canEdit($t)) {
            abort_if($t->assignee_id !== access()->id(), 403);
            if ($to === 'CANCELLED') {
                throw new BusinessRule(t('tasks.errors.cancelByLead'));
            }
            if ($from === 'DONE' || $from === 'CANCELLED') {
                throw new BusinessRule(t('tasks.errors.reopenByLead'));
            }
        }

        DB::transaction(function () use ($t, $from, $to, $beforeId, $id) {
            $data = ['status' => $to, 'sort_order' => self::positionIn($t->project_id, $to, $beforeId, $id)];
            if ($from !== $to) {
                if ($to === 'IN_PROGRESS' && ! $t->started_at) {
                    $data['started_at'] = now();
                }
                if ($to === 'DONE') {
                    $data['completed_at'] = now();
                    $data['progress_pct'] = 100;
                } elseif ($from === 'DONE') {
                    $data['completed_at'] = null;
                }
                if (Tasks::isRework($from, $to)) {
                    $data['rework_count'] = $t->rework_count + 1;
                }
            }
            $t->update($data);
            if ($from === $to) {
                return;
            }
            $actor = access()->id();
            TaskStatusHistory::create(['task_id' => $id, 'from_status' => $from, 'to_status' => $to, 'changed_by_id' => $actor]);
            Activities::log('task.status_changed', ['project_id' => $t->project_id, 'task_id' => $id], ['number' => self::code($t), 'title' => $t->title, 'from' => $from, 'to' => $to]);
            Audit::log('task.status', 'task', $id, ['status' => ['old' => $from, 'new' => $to]]);
            // Первая задача в работе — проект переходит в «В работе»
            if ($to === 'IN_PROGRESS' && in_array($t->project->status, ['NEW', 'PLANNING'], true)) {
                $projectFrom = $t->project->status;
                $t->project->update(['status' => 'IN_PROGRESS']);
                Activities::log('project.status_changed', ['project_id' => $t->project_id], ['from' => $projectFrom, 'to' => 'IN_PROGRESS', 'auto' => true]);
            }
            Outbox::publish('task.status_changed', ['taskId' => $id, 'projectId' => $t->project_id, 'from' => $from, 'to' => $to]);
        });

        return $t->fresh();
    }

    /** sort_order для карточки перед $beforeId (или в конце колонки). */
    private static function positionIn(string $projectId, string $status, ?string $beforeId, string $selfId): float
    {
        $column = fn () => Task::where('project_id', $projectId)->where('status', $status)->whereKeyNot($selfId);
        if ($beforeId) {
            $before = $column()->whereKey($beforeId)->first();
            if ($before) {
                $prev = $column()->where('sort_order', '<', $before->sort_order)->max('sort_order');

                return Tasks::sortBetween($prev === null ? null : (float) $prev, (float) $before->sort_order);
            }
        }
        $last = $column()->max('sort_order');

        return Tasks::sortBetween($last === null ? null : (float) $last, null);
    }

    /** Удаление (мягкое) — только тот, кто управляет задачами проекта. */
    public static function remove(string $id): Task
    {
        $t = ProjectAccess::task($id, 'task.update');
        self::assertProjectOpen($t->project);
        abort_unless(ProjectAccess::can($t->project_id, 'task.create'), 403);
        DB::transaction(function () use ($t, $id) {
            $t->delete();
            Activities::log('task.deleted', ['project_id' => $t->project_id, 'task_id' => $id], ['number' => self::code($t), 'title' => $t->title]);
            Audit::log('task.delete', 'task', $id, ['title' => ['old' => $t->title, 'new' => null]]);
        });

        return $t;
    }

    /** Комментарий может оставить любой, кто видит задачу (ТЗ §3.4). */
    public static function comment(string $id, string $body): TaskComment
    {
        $t = ProjectAccess::task($id);

        return DB::transaction(function () use ($t, $id, $body) {
            $c = TaskComment::create(['task_id' => $id, 'author_id' => access()->id(), 'body' => $body]);
            Activities::log('task.commented', ['project_id' => $t->project_id, 'task_id' => $id], ['number' => self::code($t), 'title' => $t->title]);

            return $c;
        });
    }
}
