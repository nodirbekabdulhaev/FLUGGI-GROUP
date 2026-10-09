<?php

namespace App\Services\Projects;

use App\Domain\Tasks;
use App\Exceptions\BusinessRule;
use App\Models\Activity;
use App\Models\Direction;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use App\Services\Crm\Activities;
use App\Services\Finance\CostLineService;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Outbox;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Проекты (ТЗ §19–21): списки, карточка, статус, шаблон, команда. */
final class ProjectService
{
    public const VIEWS = ['all', 'active', 'overdue', 'completed'];

    /** Открытые задачи с прошедшим дедлайном (ТЗ §24). */
    public static function overdueTasks($q)
    {
        return $q->whereIn('tasks.status', ProjectAccess::OPEN_TASK)->whereNotNull('tasks.deadline')->where('tasks.deadline', '<', now());
    }

    /**
     * @param  array{view?:string,status?:?string,ropId?:?string,clientId?:?string,dealId?:?string,directionId?:?string,q?:?string}  $f
     */
    public static function list(array $f, int $perPage = 25): LengthAwarePaginator
    {
        $view = $f['view'] ?? 'all';
        $q = ProjectAccess::projects()->with(['client:id,name', 'rop:id,full_name', 'manager:id,full_name', 'direction:id,name', 'deal:id,number,title']);
        if ($view === 'active') {
            $q->whereIn('projects.status', ProjectAccess::ACTIVE);
        } elseif ($view === 'completed') {
            $q->where('projects.status', 'COMPLETED');
        } elseif ($view === 'overdue') {
            $today = Tasks::companyDate(now());
            $q->whereIn('projects.status', ProjectAccess::ACTIVE)->where(fn ($w) => $w
                ->where('projects.deadline', '<', $today)
                ->orWhereExists(fn ($s) => self::overdueTasks($s->selectRaw('1')->from('tasks')
                    ->whereColumn('tasks.project_id', 'projects.id')->whereNull('tasks.deleted_at'))));
        }
        foreach (['status' => 'projects.status', 'ropId' => 'projects.rop_id', 'clientId' => 'projects.client_id', 'dealId' => 'projects.deal_id', 'directionId' => 'projects.direction_id'] as $key => $col) {
            if (! empty($f[$key])) {
                $q->where($col, $f[$key]);
            }
        }
        if (! empty($f['q'])) {
            $term = '%'.$f['q'].'%';
            $q->where(fn ($w) => $w->where('projects.name', 'like', $term)
                ->orWhereIn('projects.client_id', fn ($s) => $s->select('id')->from('clients')->where('name', 'like', $term)));
        }
        if ($view === 'completed') {
            $q->orderByDesc('projects.completed_at');
        } else {
            $q->orderByRaw('projects.deadline IS NULL')->orderBy('projects.deadline')->orderByDesc('projects.created_at');
        }
        $page = $q->paginate($perPage)->withQueryString();
        self::decorate($page->getCollection());

        return $page;
    }

    /** Добавляет к проектам счётчики задач, дни просрочки, видимость денег и сделки. */
    public static function decorate(Collection $projects): void
    {
        $counts = self::taskCounts($projects->pluck('id')->all());
        $access = access();
        $seesMoney = $access->can('payment.read') || $access->can('finance.read');
        $seesDeal = $access->can('deal.read');
        foreach ($projects as $p) {
            $p->setAttribute('task_counts', $counts[$p->id] ?? ['total' => 0, 'done' => 0, 'overdue' => 0]);
            $p->setAttribute('overdue_days', Tasks::projectOverdueDays($p->deadline?->format('Y-m-d'), in_array($p->status, ProjectAccess::ACTIVE, true), now()));
            $p->setAttribute('sees_money', $seesMoney);
            $p->setAttribute('sees_deal', $seesDeal);
        }
    }

    /** Счётчики задач: всего (без отменённых), выполнено, просрочено. */
    public static function taskCounts(array $projectIds): array
    {
        if (! $projectIds) {
            return [];
        }
        $out = [];
        $rows = DB::table('tasks')->whereIn('project_id', $projectIds)->whereNull('deleted_at')->where('status', '<>', 'CANCELLED')
            ->groupBy('project_id', 'status')->select('project_id', 'status', DB::raw('COUNT(*) AS n'))->get();
        foreach ($rows as $r) {
            $c = $out[$r->project_id] ?? ['total' => 0, 'done' => 0, 'overdue' => 0];
            $c['total'] += (int) $r->n;
            if ($r->status === 'DONE') {
                $c['done'] += (int) $r->n;
            }
            $out[$r->project_id] = $c;
        }
        $overdue = self::overdueTasks(DB::table('tasks')->whereIn('project_id', $projectIds)->whereNull('deleted_at'))
            ->groupBy('project_id')->select('project_id', DB::raw('COUNT(*) AS n'))->get();
        foreach ($overdue as $r) {
            $c = $out[$r->project_id] ?? ['total' => 0, 'done' => 0, 'overdue' => 0];
            $c['overdue'] = (int) $r->n;
            $out[$r->project_id] = $c;
        }

        return $out;
    }

    /** Карточка: проект, команда со счётчиками задач, права текущего пользователя. */
    public static function detail(string $id): Project
    {
        $project = ProjectAccess::project($id);
        $project->load(['client:id,name', 'rop:id,full_name', 'manager:id,full_name', 'direction:id,name', 'deal:id,number,title', 'template:id,name']);
        self::decorate(collect([$project]));
        $project->setAttribute('team', self::members($project->id));
        $project->setAttribute('can', [
            'update' => ProjectAccess::can($id, 'project.update'),
            'assign' => ProjectAccess::can($id, 'project.assign'),
            'createTasks' => ProjectAccess::can($id, 'task.create'),
        ]);

        return $project;
    }

    /** Команда проекта с задачами каждого участника. */
    public static function members(string $projectId): Collection
    {
        $rows = ProjectMember::with('user:id,full_name')->where('project_id', $projectId)
            ->orderByRaw("FIELD(status, 'ACTIVE', 'DONE', 'REMOVED')")->orderBy('assigned_at')->get();
        $userIds = $rows->pluck('user_id')->all();
        $byStatus = $userIds ? DB::table('tasks')->where('project_id', $projectId)->whereIn('assignee_id', $userIds)->whereNull('deleted_at')
            ->where('status', '<>', 'CANCELLED')->groupBy('assignee_id', 'status')->select('assignee_id', 'status', DB::raw('COUNT(*) AS n'))->get() : collect();
        $overdue = $userIds ? self::overdueTasks(DB::table('tasks')->where('project_id', $projectId)->whereIn('assignee_id', $userIds)->whereNull('deleted_at'))
            ->groupBy('assignee_id')->select('assignee_id', DB::raw('COUNT(*) AS n'))->pluck('n', 'assignee_id') : collect();
        foreach ($rows as $m) {
            $mine = $byStatus->where('assignee_id', $m->user_id);
            $m->setAttribute('task_counts', [
                'total' => (int) $mine->sum('n'),
                'done' => (int) $mine->where('status', 'DONE')->sum('n'),
                'overdue' => (int) ($overdue[$m->user_id] ?? 0),
            ]);
        }

        return $rows;
    }

    /** Кому можно поручить задачу: РОП, менеджер и активные участники команды. */
    public static function assignees(Project $project, ?User $current = null): array
    {
        $out = [$project->rop_id => $project->rop?->full_name.' ('.t('roles.ROP').')', $project->manager_id => $project->manager?->full_name.' ('.t('roles.MANAGER').')'];
        foreach (ProjectMember::with('user:id,full_name')->where('project_id', $project->id)->where('status', 'ACTIVE')->get() as $m) {
            $out[$m->user_id] ??= $m->user->full_name;
        }
        if ($current && ! isset($out[$current->id])) {
            $out[$current->id] = $current->full_name;
        }

        return $out;
    }

    /** @param  array<string,mixed>  $input */
    public static function update(string $id, array $input): Project
    {
        $access = access();
        $before = ProjectAccess::project($id, 'project.update');
        // Сменить РОП проекта может только тот, кто видит все проекты (CEO)
        if (! empty($input['rop_id']) && $input['rop_id'] !== $before->rop_id) {
            abort_unless($access->scope('project.update') === 'ALL', 403);
            $ok = User::where('id', $input['rop_id'])->where('status', 'ACTIVE')
                ->whereHas('role', fn ($q) => $q->whereIn('code', ['ROP', 'CEO']))->exists();
            if (! $ok) {
                throw new BusinessRule(t('projects.errors.ropRole'), ['rop_id' => t('projects.errors.ropRole')]);
            }
        }
        // Направление определяет, кто видит проект, — меняет только CEO
        if (array_key_exists('direction_id', $input) && $input['direction_id'] !== $before->direction_id) {
            abort_unless($access->scope('project.update') === 'ALL', 403);
            if ($input['direction_id'] && ! Direction::where('id', $input['direction_id'])->where('is_active', true)->exists()) {
                throw new BusinessRule(t('projects.errors.noDirection'), ['direction_id' => t('projects.errors.noDirection')]);
            }
        }
        $start = array_key_exists('start_date', $input) ? $input['start_date'] : $before->start_date?->format('Y-m-d');
        $end = array_key_exists('deadline', $input) ? $input['deadline'] : $before->deadline?->format('Y-m-d');
        if ($start && $end && $start > $end) {
            throw new BusinessRule(t('projects.errors.deadlineBeforeStart'), ['deadline' => t('projects.errors.deadlineBeforeStart')]);
        }
        $fields = ['name', 'description', 'priority', 'rop_id', 'direction_id', 'start_date', 'deadline'];
        $data = array_intersect_key($input, array_flip($fields));
        DB::transaction(function () use ($before, $data, $fields, $id) {
            $changes = Audit::diff(self::plain($before), $data, $fields);
            $before->update($data);
            if ($changes) {
                Audit::log('project.update', 'project', $id, $changes);
                Activities::log('project.updated', ['project_id' => $id], ['fields' => array_keys($changes)]);
            }
        });

        return $before->fresh();
    }

    /** Атрибуты для сравнения: даты — YYYY-MM-DD. */
    private static function plain(Project $p): array
    {
        return ['start_date' => $p->start_date?->format('Y-m-d'), 'deadline' => $p->deadline?->format('Y-m-d')] + $p->getAttributes();
    }

    /**
     * Статус проекта (ТЗ §20). Завершить можно, только когда нет открытых задач;
     * отмена — с комментарием. Закрытый проект можно вернуть в работу.
     */
    public static function setStatus(string $id, string $status, ?string $comment): void
    {
        $p = ProjectAccess::project($id, 'project.update');
        if ($p->status === $status) {
            return;
        }
        if ($status === 'COMPLETED') {
            $open = Task::where('project_id', $id)->whereIn('status', ProjectAccess::OPEN_TASK)->count();
            if ($open > 0) {
                throw new BusinessRule(t('projects.errors.openTasks', ['count' => $open]));
            }
        }
        if ($status === 'CANCELLED' && ! $comment) {
            throw new BusinessRule(t('projects.errors.cancelReason'), ['comment' => t('projects.errors.cancelReason')]);
        }
        DB::transaction(function () use ($p, $status, $comment, $id) {
            $from = $p->status;
            $p->update(['status' => $status, 'completed_at' => $status === 'COMPLETED' ? now() : null]);
            if ($status === 'COMPLETED') {
                ProjectMember::where('project_id', $id)->where('status', 'ACTIVE')->update(['status' => 'DONE']);
            }
            Activities::log('project.status_changed', ['project_id' => $id], ['from' => $from, 'to' => $status, 'comment' => $comment]);
            Audit::log('project.status', 'project', $id, ['status' => ['old' => $from, 'new' => $status]]);
            Outbox::publish('project.status_changed', ['projectId' => $id, 'from' => $from, 'to' => $status]);
        });
    }

    public static function applyTemplate(string $id, string $templateId): int
    {
        $p = ProjectAccess::project($id, 'project.update');
        if (! in_array($p->status, ProjectAccess::ACTIVE, true)) {
            throw new BusinessRule(t('projects.errors.closed'));
        }

        return DB::transaction(function () use ($p, $templateId, $id) {
            $before = $p->template_id;
            $created = Templates::apply($p, $templateId, access()->id());
            Audit::log('project.apply_template', 'project', $id, [
                'templateId' => ['old' => $before, 'new' => $templateId],
                'tasks' => ['old' => null, 'new' => $created],
            ]);

            return $created;
        });
    }

    /** Кого можно добавить в команду: активные исполнители, менеджеры и РОП. */
    public static function candidates(): Collection
    {
        return User::with(['role:id,code', 'employee:id,user_id,specialty'])->where('status', 'ACTIVE')
            ->whereHas('role', fn ($q) => $q->whereIn('code', ['EXECUTOR', 'MANAGER', 'ROP']))
            ->orderBy('full_name')->get();
    }

    /** @param  array{user_id:string, role?:?string, workload_pct?:?int, deadline?:?string}  $input */
    public static function addMember(string $id, array $input): void
    {
        $p = ProjectAccess::project($id, 'project.assign');
        if (! in_array($p->status, ProjectAccess::ACTIVE, true)) {
            throw new BusinessRule(t('projects.errors.closed'));
        }
        $user = User::with(['role', 'employee'])->where('id', $input['user_id'])->where('status', 'ACTIVE')->first();
        if (! $user) {
            throw new BusinessRule(t('projects.errors.userNotFound'), ['user_id' => t('projects.errors.userNotFound')]);
        }
        if (! in_array($user->role->code, ['EXECUTOR', 'MANAGER', 'ROP'], true)) {
            throw new BusinessRule(t('projects.errors.memberRole'), ['user_id' => t('projects.errors.memberRole')]);
        }
        $role = ($input['role'] ?? null) ?: $user->employee?->specialty;

        DB::transaction(function () use ($p, $user, $role, $input, $id) {
            $existing = ProjectMember::where('project_id', $id)->where('user_id', $user->id)->lockForUpdate()->first();
            if ($existing && $existing->status === 'ACTIVE') {
                throw new BusinessRule(t('projects.errors.alreadyMember'), ['user_id' => t('projects.errors.alreadyMember')]);
            }
            $data = [
                'role' => $role,
                'workload_pct' => $input['workload_pct'] ?? null,
                'deadline' => ($input['deadline'] ?? null) ?: null,
                'status' => 'ACTIVE',
                'assigned_by_id' => access()->id(),
                'assigned_at' => now(),
            ];
            $existing ? $existing->update($data) : ProjectMember::create(['project_id' => $id, 'user_id' => $user->id] + $data);

            // Задачи из шаблона, которые ждали исполнителя этой роли у РОП, переходят к нему
            $assigned = $role ? Task::where('project_id', $id)->where('assignee_id', $p->rop_id)->where('template_role', $role)
                ->where('status', 'TODO')->update(['assignee_id' => $user->id]) : 0;
            // Первый исполнитель — проект переходит из «Новый» в «Планирование»
            if ($p->status === 'NEW') {
                $p->update(['status' => 'PLANNING']);
            }
            // Плановые расходы его роли — новому исполнителю, с его личными ставками
            CostLineService::assign($id, $user->id);

            Activities::log('project.member_added', ['project_id' => $id], ['user' => $user->full_name, 'role' => $role, 'tasksAssigned' => $assigned]);
            Audit::log('project.member_add', 'project', $id, ['member' => ['old' => null, 'new' => $user->full_name], 'role' => ['old' => null, 'new' => $role]]);
            Outbox::publish('project.member_added', ['projectId' => $id, 'userId' => $user->id]);
        });
    }

    /** @param  array<string,mixed>  $input */
    public static function updateMember(string $id, string $memberId, array $input): void
    {
        $project = ProjectAccess::project($id, 'project.assign');
        $m = ProjectMember::with('user:id,full_name')->where('project_id', $id)->whereKey($memberId)->firstOrFail();
        $fields = ['role', 'workload_pct', 'deadline', 'status'];
        $data = array_intersect_key($input, array_flip($fields));
        DB::transaction(function () use ($project, $m, $data, $fields, $id) {
            $changes = Audit::diff(['deadline' => $m->deadline?->format('Y-m-d')] + $m->getAttributes(), $data, $fields);
            $wasRemoved = $m->status === 'REMOVED';
            $m->update($data);
            // Исключённый участник: его открытые задачи переходят к РОП проекта (ТЗ §77, Rule 5)
            if (($data['status'] ?? null) === 'REMOVED' && ! $wasRemoved) {
                $moved = Task::where('project_id', $id)->where('assignee_id', $m->user_id)->whereIn('status', ProjectAccess::OPEN_TASK)
                    ->update(['assignee_id' => $project->rop_id]);
                Activities::log('project.member_removed', ['project_id' => $id], ['user' => $m->user->full_name, 'tasksToRop' => $moved]);
            }
            if ($changes) {
                Audit::log('project.member_update', 'project', $id, ['member' => ['old' => $m->user->full_name, 'new' => $m->user->full_name]] + $changes);
            }
        });
    }

    /** Таймлайн проекта: действия по проекту и его задачам. */
    public static function timeline(string $id): Collection
    {
        ProjectAccess::project($id);

        return Activity::with('actor:id,full_name')->where('project_id', $id)->orderByDesc('created_at')->limit(200)->get();
    }

    /** Проект сделки, если пользователь может его видеть (для карточки сделки). */
    public static function forDeal(string $dealId): ?Project
    {
        if (! access()->can('project.read')) {
            return null;
        }
        $project = ProjectAccess::projects()->where('projects.deal_id', $dealId)->with(['rop:id,full_name'])->first();
        if ($project) {
            self::decorate(collect([$project]));
        }

        return $project;
    }

    /** P-00001 */
    public static function code(Project $p): string
    {
        return Format::code('P', $p->number);
    }

    /** Для выпадающих списков (расходы, поступления): видимые по праву проекты. */
    public static function options(string $code): array
    {
        return ProjectAccess::projects($code)->orderByDesc('projects.created_at')->limit(300)->get(['projects.id', 'projects.number', 'projects.name'])
            ->mapWithKeys(fn ($p) => [$p->id => Format::code('P', $p->number).' · '.$p->name])->all();
    }

    /** Проекты клиента (для карточки клиента). */
    public static function forClient(string $clientId): Collection
    {
        if (! access()->can('project.read')) {
            return collect();
        }
        $rows = ProjectAccess::projects()->where('projects.client_id', $clientId)->with('rop:id,full_name')->orderByDesc('projects.created_at')->limit(50)->get();
        self::decorate($rows);

        return $rows;
    }

    public static function query(): Builder
    {
        return ProjectAccess::projects();
    }
}
