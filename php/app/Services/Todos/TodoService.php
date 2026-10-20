<?php

namespace App\Services\Todos;

use App\Auth\Access;
use App\Exceptions\BusinessRule;
use App\Models\SmmIdeaHistory;
use App\Models\Todo;
use App\Models\User;
use App\Services\Crm\Activities;
use App\Services\Crm\CrmAccess;
use App\Services\Notifications\Notifier;
use App\Services\Smm\SmmAccess;
use App\Support\Audit;
use App\Support\Format;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Личные дела (ТЗ: «задачи не только по проектам»): звонки, письма, отчёты, платежи.
 * Поручить дело можно любому активному сотруднику (это лёгкое поручение, не проектная задача).
 * Видит и меняет дело исполнитель и тот, кто поручил; CEO (task.read = ALL) — все.
 * Дело можно связать с лидом, сделкой, клиентом или идеей SMM — оно видно в блоке «Задачи» карточки.
 */
final class TodoService
{
    public const KINDS = ['TASK', 'CALL', 'EMAIL', 'MEETING', 'PAYMENT', 'REPORT'];

    public const PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'URGENT'];

    public const PRIORITY_ORDER = ['URGENT' => 0, 'HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3];

    public const WITH = ['owner:id,full_name', 'creator:id,full_name', 'client:id,name', 'deal:id,number,title', 'lead:id,number,title', 'smmIdea:id,number,title'];

    /** Связи дела с записями: поле → [префикс номера, адрес карточки]. */
    public const LINKS = ['lead_id' => '/sales/leads/', 'deal_id' => '/sales/deals/', 'client_id' => '/clients/', 'smm_idea_id' => '/smm/'];

    public static function seesAll(Access $a): bool
    {
        return ($a->permissions['task.read'] ?? null) === 'ALL';
    }

    /** Отделы, дела которых видит РОП (task.read на уровне отдела). */
    public static function teamScope(Access $a): array
    {
        if (($a->permissions['task.read'] ?? null) !== 'TEAM') {
            return [];
        }

        return $a->headedTeamIds ?: array_values(array_filter([$a->teamId]));
    }

    public static function canEdit(Access $a, Todo $t): bool
    {
        return $t->owner_id === $a->id() || $t->creator_id === $a->id() || self::seesAll($a);
    }

    /** Поручить дело можно любому активному сотруднику; уволенному или несуществующему — нет (404). */
    public static function assertAssignable(Access $a, string $ownerId): void
    {
        if ($ownerId === $a->id()) {
            return;
        }
        if (! User::where('status', 'ACTIVE')->whereKey($ownerId)->exists()) {
            abort(404);
        }
    }

    /** Активные сотрудники для поля «Ответственный» (себя — первым). */
    public static function assignees(Access $a): array
    {
        $all = User::where('status', 'ACTIVE')->orderBy('full_name')->pluck('full_name', 'id')->all();
        $me = [$a->id() => ($all[$a->id()] ?? $a->user->full_name).' ('.t('todos.me').')'];
        unset($all[$a->id()]);

        return $me + $all;
    }

    /**
     * Связь с клиентом / сделкой / лидом / идеей SMM — только с теми, что пользователь видит (иначе 404).
     * При изменении дела проверяются только изменённые связи: исполнитель может не видеть запись.
     */
    private static function links(array $input, ?Todo $before = null): array
    {
        $changed = fn (string $key) => ! empty($input[$key]) && $input[$key] !== $before?->{$key};
        $out = [];
        if ($changed('deal_id')) {
            $deal = CrmAccess::deal($input['deal_id']);
            $out['deal_id'] = $deal->id;
            $out['client_id'] = $deal->client_id;
        } elseif (array_key_exists('deal_id', $input) && empty($input['deal_id'])) {
            $out['deal_id'] = null;
        }
        if ($changed('client_id') && ! isset($out['client_id'])) {
            $out['client_id'] = CrmAccess::client($input['client_id'])->id;
        } elseif (array_key_exists('client_id', $input) && empty($input['client_id']) && empty($input['deal_id'] ?? $before?->deal_id)) {
            $out['client_id'] = null;
        }
        if ($changed('lead_id')) {
            $out['lead_id'] = CrmAccess::lead($input['lead_id'])->id;
        } elseif (array_key_exists('lead_id', $input) && empty($input['lead_id'])) {
            $out['lead_id'] = null;
        }
        if ($changed('smm_idea_id')) {
            abort_unless(SmmAccess::allowed(), 404);
            $out['smm_idea_id'] = SmmAccess::idea($input['smm_idea_id'])->id;
        } elseif (array_key_exists('smm_idea_id', $input) && empty($input['smm_idea_id'])) {
            $out['smm_idea_id'] = null;
        }

        return $out;
    }

    /** Запись, к которой привязано дело: [поле, id, «L-00001 · Название», адрес] или null. */
    public static function record(Todo $t): ?array
    {
        return match (true) {
            (bool) $t->lead_id => ['lead_id', $t->lead_id, Format::code('L', $t->lead?->number).' · '.$t->lead?->title, self::LINKS['lead_id'].$t->lead_id],
            (bool) $t->deal_id => ['deal_id', $t->deal_id, Format::code('D', $t->deal?->number).' · '.$t->deal?->title, self::LINKS['deal_id'].$t->deal_id],
            (bool) $t->smm_idea_id => ['smm_idea_id', $t->smm_idea_id, $t->smmIdea?->code().' · '.$t->smmIdea?->title, self::LINKS['smm_idea_id'].$t->smm_idea_id],
            (bool) $t->client_id => ['client_id', $t->client_id, (string) $t->client?->name, self::LINKS['client_id'].$t->client_id],
            default => null,
        };
    }

    /** Подпись записи для уведомления и ленты: «Лид L-00001 · Сайт». */
    public static function recordLabel(Todo $t): ?string
    {
        $r = self::record($t);

        return $r ? t('todos.recordKinds.'.$r[0]).' '.$r[2] : null;
    }

    /**
     * Блок «Задачи» в карточке записи: открытые (по сроку), затем выполненные.
     *
     * @return Collection<int, Todo>
     */
    public static function forRecord(string $column, string $id): Collection
    {
        abort_unless(array_key_exists($column, self::LINKS), 404);

        return Todo::with(self::WITH)->where($column, $id)->whereIn('status', ['OPEN', 'DONE'])
            ->orderByRaw("FIELD(status, 'OPEN', 'DONE')")
            ->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderByDesc('completed_at')->orderByDesc('created_at')
            ->limit(200)->get();
    }

    public static function find(Access $a, string $id): Todo
    {
        $t = Todo::with(self::WITH)->find($id);
        if (! $t || ! self::canEdit($a, $t)) {
            abort(404);
        }

        return $t;
    }

    /** Выборка для вида: mine — мои; assigned — поручил я; all — CEO: компания, РОП: свой отдел. */
    public static function query(Access $a, string $view): Builder
    {
        $q = Todo::query()->with(self::WITH);
        if ($view === 'all' && ! self::seesAll($a)) {
            $teams = self::teamScope($a);
            if (! $teams) {
                abort(403);
            }
            $q->where(fn ($w) => $w->whereIn('owner_id', User::withTrashed()->whereIn('team_id', $teams)->select('id'))->orWhere('owner_id', $a->id()));
        }
        if ($view === 'mine') {
            $q->where('owner_id', $a->id());
        }
        if ($view === 'assigned') {
            $q->where('creator_id', $a->id())->where('owner_id', '<>', $a->id());
        }

        return $q;
    }

    public static function list(Access $a, string $view, ?string $status, int $perPage = 50, array $filters = []): LengthAwarePaginator
    {
        return self::query($a, $view)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($filters['deal_id'] ?? null, fn ($q, $v) => $q->where('deal_id', $v))
            ->orderByRaw("FIELD(status, 'OPEN', 'DONE', 'CANCELLED')")
            ->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderByDesc('created_at')
            ->paginate($perPage)->withQueryString();
    }

    /** Панель «Список дел»: только мои открытые личные дела. */
    public static function dock(Access $a): array
    {
        return Todo::with(self::WITH)->where('owner_id', $a->id())->where('status', 'OPEN')
            ->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderByDesc('created_at')->limit(200)->get()
            ->map(fn ($t) => self::dto($a, $t))->all();
    }

    public static function overdue(Todo $t): bool
    {
        return $t->status === 'OPEN' && $t->due_at && $t->due_at->isPast();
    }

    /** «Сегодня 18:00», «Завтра 10:00», «12.10.2026», «Без срока». */
    public static function dueLabel(?\DateTimeInterface $due): string
    {
        if (! $due) {
            return t('todos.noDue');
        }
        $local = Format::local($due);
        $today = Format::today();
        if ($local->isSameDay($today)) {
            return t('todos.today', ['time' => $local->format('H:i')]);
        }
        if ($local->isSameDay($today->addDay())) {
            return t('todos.tomorrow', ['time' => $local->format('H:i')]);
        }

        return $local->format('d.m.Y');
    }

    public static function dto(Access $a, Todo $t): array
    {
        return [
            'id' => $t->id,
            'number' => Format::code('TD', $t->number),
            'title' => $t->title,
            'description' => $t->description,
            'kind' => $t->kind,
            'kindLabel' => t('todos.kinds.'.$t->kind),
            'priority' => $t->priority,
            'status' => $t->status,
            'dueAt' => $t->due_at?->toIso8601ZuluString('millisecond'),
            'dueLocal' => Format::toLocalInput($t->due_at),
            'dueLabel' => self::dueLabel($t->due_at),
            'overdue' => self::overdue($t),
            'owner' => ['id' => $t->owner_id, 'name' => $t->owner?->full_name],
            'creator' => ['id' => $t->creator_id, 'name' => $t->creator?->full_name],
            'client' => $t->client ? ['id' => $t->client->id, 'name' => $t->client->name] : null,
            'deal' => $t->deal ? ['id' => $t->deal->id, 'number' => Format::code('D', $t->deal->number), 'name' => $t->deal->title] : null,
            'lead' => $t->lead ? ['id' => $t->lead->id, 'number' => Format::code('L', $t->lead->number), 'name' => $t->lead->title] : null,
            'smmIdea' => $t->smmIdea ? ['id' => $t->smmIdea->id, 'number' => $t->smmIdea->code(), 'name' => $t->smmIdea->title] : null,
            'record' => ($r = self::record($t)) ? ['kind' => $r[0], 'label' => t('todos.recordKinds.'.$r[0]).' '.$r[2], 'url' => $r[3]] : null,
            'recurring' => (bool) $t->recurring_id,
            'completedAt' => $t->completed_at?->toIso8601ZuluString('millisecond'),
            'can' => ['update' => self::canEdit($a, $t)],
        ];
    }

    /** Уведомление исполнителю: in-app и Telegram; ссылка — на запись, к которой привязано дело. */
    private static function notifyAssigned(Todo $t, string $ownerId, string $key, string $by): void
    {
        $record = self::record($t);
        Notifier::notify([$ownerId], 'todo.assigned', function () use ($t, $key, $by) {
            $label = self::recordLabel($t);
            $body = $t->title.($label ? "\n".$label : '').($t->due_at ? "\n".t('todos.due').': '.Format::dateTime($t->due_at) : '');

            return [t('todos.notify.'.$key, ['name' => $by]), $body];
        }, $record ? $record[3] : '/todos');
    }

    public static function create(Access $a, array $input): Todo
    {
        $ownerId = ($input['owner_id'] ?? null) ?: $a->id();
        self::assertAssignable($a, $ownerId);
        $links = self::links($input);
        $t = DB::transaction(function () use ($a, $input, $ownerId, $links) {
            $t = Todo::create([
                'title' => $input['title'],
                'description' => $input['description'] ?? null,
                'kind' => $input['kind'] ?? 'TASK',
                'priority' => $input['priority'] ?? 'MEDIUM',
                'due_at' => Format::fromLocalInput($input['due_at'] ?? null),
                'owner_id' => $ownerId,
                'creator_id' => $a->id(),
            ] + $links);
            $t = Todo::with(self::WITH)->find($t->id);
            Audit::log('todo.create', 'todo', $t->id, [
                'title' => ['old' => null, 'new' => $t->title],
                'ownerId' => ['old' => null, 'new' => $ownerId],
            ] + array_map(fn ($v) => ['old' => null, 'new' => $v], array_filter($links)));
            self::logOnRecord($t);

            return $t;
        });
        if ($ownerId !== $a->id()) {
            self::notifyAssigned($t, $ownerId, 'assigned', $a->user->full_name);
        }

        return $t;
    }

    /** «Поставлена задача … для …» — в ленте лида, сделки, клиента или в истории идеи SMM. */
    private static function logOnRecord(Todo $t): void
    {
        $payload = ['todoId' => $t->id, 'title' => $t->title, 'owner' => $t->owner?->full_name];
        $crm = array_filter(['lead_id' => $t->lead_id, 'deal_id' => $t->deal_id, 'client_id' => $t->client_id]);
        if ($crm) {
            Activities::log('todo.created', $crm, $payload);
        }
        if ($t->smm_idea_id) {
            SmmIdeaHistory::create([
                'idea_id' => $t->smm_idea_id, 'user_id' => access(false)?->id(), 'action' => 'todo_created',
                'changes' => null, 'comment' => t('todos.recordLog', ['title' => $t->title, 'name' => (string) $t->owner?->full_name]), 'created_at' => now(),
            ]);
        }
    }

    public static function update(Access $a, string $id, array $input): Todo
    {
        $before = self::find($a, $id);
        $prevOwner = $before->owner_id;
        $newOwner = $input['owner_id'] ?? null;
        if ($newOwner && $newOwner !== $before->owner_id) {
            self::assertAssignable($a, $newOwner);
        }
        $links = self::links($input, $before);
        $data = array_intersect_key($input, array_flip(['title', 'description', 'kind', 'priority']));
        if (array_key_exists('due_at', $input)) {
            $data['due_at'] = Format::fromLocalInput($input['due_at']);
        }
        if ($newOwner) {
            $data['owner_id'] = $newOwner;
        }
        $data += $links;
        DB::transaction(function () use ($before, $data) {
            $changes = Audit::diff($before, $data, ['title', 'description', 'kind', 'priority', 'due_at', 'owner_id', 'client_id', 'deal_id', 'lead_id', 'smm_idea_id']);
            $before->update($data);
            if ($changes) {
                Audit::log('todo.update', 'todo', $before->id, $changes);
            }
        });
        $t = Todo::with(self::WITH)->find($id);
        if ($newOwner && $newOwner !== $prevOwner && $newOwner !== $a->id()) {
            self::notifyAssigned($t, $newOwner, 'transferred', $a->user->full_name);
        }

        return $t;
    }

    public static function setStatus(Access $a, string $id, string $status): Todo
    {
        $before = self::find($a, $id);
        if ($before->status === $status) {
            throw new BusinessRule(t('todos.errors.sameStatus'));
        }
        $from = $before->status;
        DB::transaction(function () use ($before, $status, $from) {
            $before->update(['status' => $status, 'completed_at' => $status === 'DONE' ? now() : null]);
            Audit::log('todo.status', 'todo', $before->id, ['status' => ['old' => $from, 'new' => $status]]);
        });
        $t = Todo::with(self::WITH)->find($id);
        // Поручившему — что дело выполнено
        if ($status === 'DONE' && $t->creator_id !== $a->id()) {
            $owner = $t->owner?->full_name;
            Notifier::notify([$t->creator_id], 'todo.assigned', fn () => [t('todos.notify.done', ['name' => $owner]), $t->title], '/todos?view=assigned');
        }

        return $t;
    }

    public static function remove(Access $a, string $id): void
    {
        $t = self::find($a, $id);
        if ($t->creator_id !== $a->id() && ! self::seesAll($a)) {
            throw new HttpException(403, t('todos.errors.deleteOwn'));
        }
        DB::transaction(function () use ($t) {
            $t->delete();
            Audit::log('todo.delete', 'todo', $t->id, ['title' => ['old' => $t->title, 'new' => null]]);
        });
    }
}
