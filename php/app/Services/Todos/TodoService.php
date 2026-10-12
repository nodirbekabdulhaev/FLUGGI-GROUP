<?php

namespace App\Services\Todos;

use App\Auth\Access;
use App\Exceptions\BusinessRule;
use App\Models\Todo;
use App\Models\User;
use App\Services\Crm\CrmAccess;
use App\Services\Notifications\Notifier;
use App\Support\Format;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Личные дела (ТЗ: «задачи не только по проектам»): звонки, письма, отчёты, платежи.
 * Видит и меняет дело исполнитель и тот, кто поручил; CEO (task.read = ALL) — все.
 */
final class TodoService
{
    public const KINDS = ['TASK', 'CALL', 'EMAIL', 'MEETING', 'PAYMENT', 'REPORT'];

    public const PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'URGENT'];

    public const PRIORITY_ORDER = ['URGENT' => 0, 'HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3];

    public const WITH = ['owner:id,full_name', 'creator:id,full_name', 'client:id,name', 'deal:id,number,title', 'lead:id,number,title'];

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

    /** Поручить дело можно себе; другим — в пределах права task.create (РОП — отделу, CEO — всем). */
    public static function assertAssignable(Access $a, string $ownerId): void
    {
        if ($ownerId === $a->id()) {
            return;
        }
        $scope = $a->permissions['task.create'] ?? null;
        $user = User::where('status', 'ACTIVE')->whereKey($ownerId)->first(['id', 'team_id']);
        if (! $user) {
            abort(404);
        }
        if ($scope === 'ALL' || ($scope === 'TEAM' && $user->team_id && in_array($user->team_id, $a->teamIds(), true))) {
            return;
        }
        throw new HttpException(403, t('todos.errors.assignTeam'));
    }

    /** Связь с клиентом / сделкой / лидом — только с теми, что пользователь видит (иначе 404). */
    private static function links(array $input): array
    {
        $out = [];
        if (! empty($input['deal_id'])) {
            $deal = CrmAccess::deal($input['deal_id']);
            $out['deal_id'] = $deal->id;
            $out['client_id'] = $deal->client_id;
        } elseif (array_key_exists('deal_id', $input)) {
            $out['deal_id'] = null;
        }
        if (! empty($input['client_id'])) {
            $out['client_id'] = CrmAccess::client($input['client_id'])->id;
        } elseif (array_key_exists('client_id', $input) && empty($input['deal_id'])) {
            $out['client_id'] = null;
        }
        if (! empty($input['lead_id'])) {
            $out['lead_id'] = CrmAccess::lead($input['lead_id'])->id;
        } elseif (array_key_exists('lead_id', $input)) {
            $out['lead_id'] = null;
        }

        return $out;
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
            'recurring' => (bool) $t->recurring_id,
            'completedAt' => $t->completed_at?->toIso8601ZuluString('millisecond'),
            'can' => ['update' => self::canEdit($a, $t)],
        ];
    }

    public static function create(Access $a, array $input): Todo
    {
        $ownerId = ($input['owner_id'] ?? null) ?: $a->id();
        self::assertAssignable($a, $ownerId);
        $links = self::links($input);
        $t = DB::transaction(fn () => Todo::create([
            'title' => $input['title'],
            'description' => $input['description'] ?? null,
            'kind' => $input['kind'] ?? 'TASK',
            'priority' => $input['priority'] ?? 'MEDIUM',
            'due_at' => Format::fromLocalInput($input['due_at'] ?? null),
            'owner_id' => $ownerId,
            'creator_id' => $a->id(),
        ] + $links));
        $t = Todo::with(self::WITH)->find($t->id);
        if ($ownerId !== $a->id()) {
            $creator = $a->user->full_name;
            Notifier::notify([$ownerId], 'todo.assigned', fn () => [t('todos.notify.assigned', ['name' => $creator]), $t->title], '/todos');
        }

        return $t;
    }

    public static function update(Access $a, string $id, array $input): Todo
    {
        $before = self::find($a, $id);
        $prevOwner = $before->owner_id;
        $newOwner = $input['owner_id'] ?? null;
        if ($newOwner && $newOwner !== $before->owner_id) {
            self::assertAssignable($a, $newOwner);
        }
        $links = self::links($input);
        $data = array_intersect_key($input, array_flip(['title', 'description', 'kind', 'priority']));
        if (array_key_exists('due_at', $input)) {
            $data['due_at'] = Format::fromLocalInput($input['due_at']);
        }
        if ($newOwner) {
            $data['owner_id'] = $newOwner;
        }
        DB::transaction(fn () => $before->update($data + $links));
        $t = Todo::with(self::WITH)->find($id);
        if ($newOwner && $newOwner !== $prevOwner && $newOwner !== $a->id()) {
            Notifier::notify([$newOwner], 'todo.assigned', fn () => [t('todos.notify.transferred'), $t->title], '/todos');
        }

        return $t;
    }

    public static function setStatus(Access $a, string $id, string $status): Todo
    {
        $before = self::find($a, $id);
        if ($before->status === $status) {
            throw new BusinessRule(t('todos.errors.sameStatus'));
        }
        DB::transaction(fn () => $before->update(['status' => $status, 'completed_at' => $status === 'DONE' ? now() : null]));
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
        $t->delete();
    }
}
