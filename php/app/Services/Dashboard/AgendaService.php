<?php

namespace App\Services\Dashboard;

use App\Auth\Access;
use App\Models\FollowUp;
use App\Models\Payment;
use App\Models\Todo;
use App\Services\Automation\FollowUpService;
use App\Services\Crm\CrmAccess;
use App\Services\Projects\ProjectAccess;
use App\Services\Smm\SmmAccess;
use App\Services\Todos\TodoService;
use App\Support\Format;
use App\Support\Lang;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * «Мой день» на главной: что ждёт сотрудника за день / неделю / месяц / год (по Ташкенту).
 * Каждый вид записей — в своей области видимости (те же фильтры, что и в разделах):
 * встречи — meeting.read, задачи проектов — task.read, follow-up — client.read, сделки — deal.read,
 * идеи SMM — доступ к разделу SMM, ожидаемые оплаты — payment.read; личные дела — свои.
 * who=me — только свои записи (ответственный, участник, исполнитель); who=all — всё в области права.
 */
final class AgendaService
{
    public const RANGES = ['day', 'week', 'month', 'year'];

    public const TZ = 'Asia/Tashkent';

    /** Не больше записей одного вида за период — год у CEO может быть большим. */
    private const LIMIT = 500;

    private const OVERDUE_LIMIT = 30;

    /**
     * Границы периода по Ташкенту, в UTC; to — не включительно. Неделя — с понедельника.
     *
     * @return array{range:string, from:CarbonImmutable, to:CarbonImmutable, fromDate:string, toDate:string, anchor:string, prev:string, next:string, today:string}
     */
    public static function range(?string $range, ?string $date = null, ?CarbonImmutable $now = null): array
    {
        $range = in_array($range, self::RANGES, true) ? $range : 'day';
        $today = ($now ?? CarbonImmutable::now())->setTimezone(self::TZ)->format('Y-m-d');
        $valid = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && CarbonImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') === $date;
        $anchor = CarbonImmutable::createFromFormat('!Y-m-d', $valid ? $date : $today, self::TZ);
        [$a, $b, $prev, $next] = match ($range) {
            'week' => [$s = $anchor->subDays((int) $anchor->format('N') - 1), $s->addDays(7), $s->subDays(7), $s->addDays(7)],
            'month' => [$s = $anchor->startOfMonth(), $s->addMonthNoOverflow(), $s->subMonthNoOverflow(), $s->addMonthNoOverflow()],
            'year' => [$s = $anchor->startOfYear(), $s->addYear(), $s->subYear(), $s->addYear()],
            default => [$anchor, $anchor->addDay(), $anchor->subDay(), $anchor->addDay()],
        };

        return [
            'range' => $range,
            'from' => $a->utc(),
            'to' => $b->utc(),
            'fromDate' => $a->format('Y-m-d'),
            'toDate' => $b->format('Y-m-d'),
            'anchor' => $anchor->format('Y-m-d'),
            'prev' => $prev->format('Y-m-d'),
            'next' => $next->format('Y-m-d'),
            'today' => $today,
        ];
    }

    /** Может ли пользователь смотреть «всех» (область шире своей хотя бы у одного вида записей). */
    public static function canSeeAll(Access $a): bool
    {
        foreach (['meeting.read', 'task.read', 'deal.read', 'payment.read', 'client.read'] as $code) {
            if ($a->can($code, 'TEAM')) {
                return true;
            }
        }

        return false;
    }

    public static function get(Access $a, ?string $range, ?string $date = null, ?string $who = null, ?CarbonImmutable $now = null): array
    {
        $r = self::range($range, $date, $now);
        $all = $who === 'all' && self::canSeeAll($a);
        $now ??= CarbonImmutable::now();
        $items = [];
        $overdue = [];
        $counts = [];
        $sources = [
            'meeting' => fn () => $a->can('meeting.read') ? self::meetings($a, $r, $all) : null,
            'task' => fn () => $a->can('task.read') ? self::tasks($a, $r, $all, $now) : null,
            'todo' => fn () => self::todos($a, $r, $all, $now),
            'followUp' => fn () => $a->can('client.read') ? self::followUps($a, $r, $all) : null,
            'deal' => fn () => $a->can('deal.read') ? self::deals($a, $r, $all) : null,
            'smm' => fn () => SmmAccess::allowed() ? self::smm($a, $r, $all) : null,
            'payment' => fn () => $a->can('payment.read') ? self::payments($a, $r, $all) : null,
        ];
        foreach ($sources as $kind => $load) {
            $res = $load();
            if ($res === null) {
                continue;
            }
            [$list, $late] = $res;
            $counts[$kind] = count($list);
            array_push($items, ...$list);
            array_push($overdue, ...$late);
        }
        $sort = fn ($x, $y) => [$x['date'], $x['time'] === null ? 1 : 0, $x['time'] ?? '', $x['kind']] <=> [$y['date'], $y['time'] === null ? 1 : 0, $y['time'] ?? '', $y['kind']];
        usort($items, $sort);
        usort($overdue, $sort);

        return [
            'range' => $r['range'],
            'from' => $r['fromDate'],
            'to' => CarbonImmutable::parse($r['toDate'])->subDay()->format('Y-m-d'),
            'anchor' => $r['anchor'],
            'prev' => $r['prev'],
            'next' => $r['next'],
            'today' => $r['today'],
            'who' => $all ? 'all' : 'me',
            'canSeeAll' => self::canSeeAll($a),
            'kinds' => array_keys($counts),
            'counts' => $counts,
            'total' => count($items),
            'items' => $items,
            'overdue' => $overdue,
        ];
    }

    private static function local(?\DateTimeInterface $at): ?CarbonImmutable
    {
        return $at ? CarbonImmutable::instance($at)->setTimezone(self::TZ) : null;
    }

    private static function item(string $kind, string $id, string $date, ?string $time, string $title, ?string $sub, ?string $href, ?array $badge = null, array $extra = []): array
    {
        return ['kind' => $kind, 'id' => $id, 'date' => $date, 'time' => $time, 'title' => $title, 'sub' => $sub, 'href' => $href, 'badge' => $badge, 'overdue' => false] + $extra;
    }

    private static function inRange(Builder $q, string $col, array $r, bool $date = false): Builder
    {
        return $q->where($col, '>=', $date ? $r['fromDate'] : $r['from'])->where($col, '<', $date ? $r['toDate'] : $r['to']);
    }

    private const MEETING_COLORS = ['SCHEDULED' => 'blue', 'CONFIRMED' => 'green', 'DONE' => 'gray', 'RESCHEDULED' => 'yellow', 'NO_SHOW' => 'red'];

    /** Встречи: свои — ответственный, РОП встречи или участник (meeting_participants). Отменённые не показываем. */
    private static function meetings(Access $a, array $r, bool $all): array
    {
        $uid = $a->id();
        $q = CrmAccess::meetings()->with(['lead:id,number,title', 'deal:id,number,title', 'client:id,name', 'participants:id,full_name'])
            ->where('meetings.status', '<>', 'CANCELLED')
            ->when(! $all, fn ($q) => $q->where(fn ($w) => $w->where('meetings.manager_id', $uid)->orWhere('meetings.rop_id', $uid)
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('meeting_participants as mp')->whereColumn('mp.meeting_id', 'meetings.id')->where('mp.user_id', $uid))));
        $rows = self::inRange($q, 'meetings.starts_at', $r)->orderBy('meetings.starts_at')->limit(self::LIMIT)->get();
        $list = $rows->map(function ($m) {
            $at = self::local($m->starts_at);
            [$title, $href] = match (true) {
                (bool) $m->deal => [$m->deal->title, '/sales/deals/'.$m->deal_id],
                (bool) $m->lead => [$m->lead->title, '/sales/leads/'.$m->lead_id],
                default => [$m->client?->name ?? t('crm.meetingType.'.$m->type), $m->client_id ? '/clients/'.$m->client_id : '/sales/meetings'],
            };
            $sub = trim(t('crm.meetingType.'.$m->type).($m->client && ($m->deal || $m->lead) ? ' · '.$m->client->name : ''));

            return self::item('meeting', $m->id, $at->format('Y-m-d'), $at->format('H:i'), $title, $sub, $href,
                ['text' => t('crm.meetingStatus.'.$m->status), 'color' => self::MEETING_COLORS[$m->status] ?? 'gray'],
                ['end' => $at->addMinutes($m->duration_min)->format('H:i'), 'people' => $m->participants->pluck('full_name')->all(), 'link' => $m->link ?: null]);
        })->all();

        return [$list, []];
    }

    private const TASK_COLORS = ['TODO' => 'gray', 'IN_PROGRESS' => 'blue', 'REVIEW' => 'yellow', 'BLOCKED' => 'red'];

    /** Задачи проектов: свои — где исполнитель; открытые, со сроком в периоде; просроченные — отдельно. */
    private static function tasks(Access $a, array $r, bool $all, CarbonImmutable $now): array
    {
        $base = fn () => ProjectAccess::tasks()->with('project:id,number,name')->whereIn('tasks.status', ProjectAccess::OPEN_TASK)
            ->when(! $all, fn ($q) => $q->where('tasks.assignee_id', $a->id()))
            ->when($all, fn ($q) => $q->with('assignee:id,full_name'));
        $map = function ($t, bool $late) use ($all) {
            $at = self::local($t->deadline);
            $sub = trim(($t->project?->name ?? '').($all && $t->assignee ? ' · '.$t->assignee->full_name : ''), ' ·');

            return ['overdue' => $late] + self::item('task', $t->id, $at->format('Y-m-d'), $at->format('H:i') === '00:00' ? null : $at->format('H:i'), $t->title, $sub ?: null,
                '/projects/'.$t->project_id.'?task='.$t->id, ['text' => t('tasks.status.'.$t->status), 'color' => self::TASK_COLORS[$t->status] ?? 'gray']);
        };
        $list = self::inRange($base(), 'tasks.deadline', $r)->orderBy('tasks.deadline')->limit(self::LIMIT)->get()
            ->map(fn ($t) => $map($t, $t->deadline < $now))->all();
        $late = $base()->where('tasks.deadline', '<', min($now, $r['from']))->orderBy('tasks.deadline')->limit(self::OVERDUE_LIMIT)->get()
            ->map(fn ($t) => $map($t, true))->all();

        return [$list, $late];
    }

    /** Личные дела: свои открытые; «все» — как в разделе «Дела» (CEO — компания, РОП — свой отдел). */
    private static function todos(Access $a, array $r, bool $all, CarbonImmutable $now): array
    {
        $view = $all && (TodoService::seesAll($a) || TodoService::teamScope($a)) ? 'all' : 'mine';
        $base = fn () => TodoService::query($a, $view)->where('status', 'OPEN')->whereNull('deleted_at');
        $map = function (Todo $t, bool $late) use ($view, $a) {
            $at = self::local($t->due_at);
            $record = TodoService::recordLabel($t);
            $who = $view === 'all' && $t->owner_id !== $a->id() ? $t->owner?->full_name : null;

            return ['overdue' => $late] + self::item('todo', $t->id, $at->format('Y-m-d'), $at->format('H:i') === '00:00' ? null : $at->format('H:i'), $t->title,
                implode(' · ', array_filter([t('todos.kinds.'.$t->kind), $record, $who])) ?: null,
                TodoService::record($t)[3] ?? '/todos', $t->priority === 'URGENT' || $t->priority === 'HIGH' ? ['text' => t('crm.priority.'.$t->priority), 'color' => $t->priority === 'URGENT' ? 'red' : 'yellow'] : null);
        };
        $list = self::inRange($base(), 'due_at', $r)->orderBy('due_at')->limit(self::LIMIT)->get()->map(fn ($t) => $map($t, $t->due_at < $now))->all();
        $late = $base()->where('due_at', '<', min($now, $r['from']))->orderBy('due_at')->limit(self::OVERDUE_LIMIT)->get()->map(fn ($t) => $map($t, true))->all();

        return [$list, $late];
    }

    /** Follow-up по клиентам (повторные продажи): ожидающие, срок в периоде. */
    private static function followUps(Access $a, array $r, bool $all): array
    {
        $base = fn () => FollowUpService::scope($a)->with('client:id,name')->where('status', 'PENDING')
            ->when(! $all, fn ($q) => $q->where('owner_id', $a->id()));
        $map = fn (FollowUp $f, bool $late) => ['overdue' => $late] + self::item('followUp', $f->id, $f->due_date->format('Y-m-d'), null,
            $f->client?->name ?? '—', t('followUps.kinds.'.$f->kind), '/sales/follow-ups', null);
        $list = self::inRange($base(), 'due_date', $r, true)->orderBy('due_date')->limit(self::LIMIT)->get()->map(fn ($f) => $map($f, $f->due_date->format('Y-m-d') < $r['today']))->all();
        $late = $base()->where('due_date', '<', min($r['today'], $r['fromDate']))->orderBy('due_date')->limit(self::OVERDUE_LIMIT)->get()->map(fn ($f) => $map($f, true))->all();

        return [$list, $late];
    }

    /** Открытые сделки с ожидаемой датой закрытия в периоде — следующий шаг по сделке. */
    private static function deals(Access $a, array $r, bool $all): array
    {
        $q = CrmAccess::deals()->with(['client:id,name', 'stage:id,name_ru,name_uz,name_en'])->where('deals.status', 'OPEN')
            ->when(! $all, fn ($q) => $q->where('deals.owner_id', $a->id()));
        $list = self::inRange($q, 'deals.expected_close_date', $r, true)->orderBy('deals.expected_close_date')->limit(self::LIMIT)->get()
            ->map(fn ($d) => self::item('deal', $d->id, $d->expected_close_date->format('Y-m-d'), null, $d->title,
                implode(' · ', array_filter([$d->client?->name, $d->stage ? Lang::name($d->stage) : null])),
                '/sales/deals/'.$d->id, null, ['amount' => Format::moneyShort((string) $d->amount, $d->currency)]))->all();

        return [$list, []];
    }

    /** Идеи SMM к публикации: запланированная дата в периоде, ещё не опубликованы и не отклонены. */
    private static function smm(Access $a, array $r, bool $all): array
    {
        $uid = $a->id();
        $base = fn () => SmmAccess::ideas()->whereNotIn('status', ['PUBLISHED', 'REJECTED'])
            ->when(! $all, fn ($q) => $q->where(fn ($w) => $w->where('assignee_id', $uid)->orWhere('author_id', $uid)->orWhere('videographer_id', $uid)->orWhere('editor_id', $uid)));
        $map = fn ($i, bool $late) => ['overdue' => $late] + self::item('smm', $i->id, $i->planned_publish_date->format('Y-m-d'), null, $i->title, $i->code(),
            '/smm/'.$i->id, ['text' => t('smm.status.'.$i->status), 'color' => $i->status === 'SCHEDULED' ? 'green' : 'blue']);
        $list = self::inRange($base(), 'planned_publish_date', $r, true)->orderBy('planned_publish_date')->limit(self::LIMIT)->get()
            ->map(fn ($i) => $map($i, $i->planned_publish_date->format('Y-m-d') < $r['today']))->all();
        $late = $base()->where('planned_publish_date', '<', min($r['today'], $r['fromDate']))->orderBy('planned_publish_date')->limit(self::OVERDUE_LIMIT)->get()->map(fn ($i) => $map($i, true))->all();

        return [$list, $late];
    }

    /** Ожидаемые оплаты по графику (PENDING, кроме возвратов) — по видимости сделки; свои — по своим сделкам. */
    private static function payments(Access $a, array $r, bool $all): array
    {
        $base = fn () => CrmAccess::byDeal(Payment::query(), 'payment.read')->with(['client:id,name', 'deal:id,title'])
            ->where('status', 'PENDING')->where('type', '<>', 'REFUND')->whereNotNull('due_date')
            ->when(! $all, fn ($q) => $q->whereIn('deal_id', CrmAccess::deals('payment.read')->where('deals.owner_id', $a->id())->select('deals.id')));
        $map = fn (Payment $p, bool $late) => ['overdue' => $late] + self::item('payment', $p->id, $p->due_date->format('Y-m-d'), null,
            $p->client?->name ?? '—', $p->deal?->title, '/sales/deals/'.$p->deal_id, ['text' => t('sales.paymentType.'.$p->type), 'color' => 'blue'],
            ['amount' => Format::moneyShort((string) $p->amount, $p->currency)]);
        $list = self::inRange($base(), 'due_date', $r, true)->orderBy('due_date')->limit(self::LIMIT)->get()->map(fn ($p) => $map($p, $p->due_date->format('Y-m-d') < $r['today']))->all();
        $late = $base()->where('due_date', '<', min($r['today'], $r['fromDate']))->orderBy('due_date')->limit(self::OVERDUE_LIMIT)->get()->map(fn ($p) => $map($p, true))->all();

        return [$list, $late];
    }
}
