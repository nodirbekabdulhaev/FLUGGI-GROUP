<?php

namespace App\Services\People;

use App\Domain\Finance;
use App\Domain\People;
use App\Domain\Tasks;
use App\Models\KpiTarget;
use App\Models\User;
use App\Services\Projects\ProjectAccess;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * KPI сотрудников (ТЗ §28–31). Считается «на лету» из исходных таблиц за период —
 * один источник правды с CRM, проектами и оплатами.
 */
final class KpiService
{
    public const GROUPS = ['MANAGER', 'ROP', 'EXECUTOR'];

    public const METRICS = ['REVENUE', 'ORDERS', 'LEADS', 'MEETINGS', 'TASKS'];

    /** Метрики, которые имеет смысл задавать роли. */
    public const METRICS_FOR = [
        'MANAGER' => ['REVENUE', 'ORDERS', 'LEADS', 'MEETINGS'],
        'ROP' => ['REVENUE', 'ORDERS', 'LEADS', 'MEETINGS'],
        'EXECUTOR' => ['TASKS'],
    ];

    /** Сотрудники, чьи показатели видит пользователь (право $code). */
    public static function users(string $code): Builder
    {
        return access()->restrict(User::query(), $code,
            own: fn ($q, $uid) => $q->where('users.id', $uid),
            team: fn ($q, $teamIds, $uid) => $q->whereIn('users.team_id', $teamIds ?: ['-'])->orWhere('users.id', $uid),
        );
    }

    private static function pct(int $part, int $total): ?string
    {
        return $total > 0 ? number_format($part / $total * 100, 2, '.', '') : null;
    }

    private static function avg(BigDecimal $sum, int $n): string
    {
        return (string) ($n > 0 ? $sum->dividedBy($n, 2, RoundingMode::HalfUp) : BigDecimal::zero()->toScale(2));
    }

    private static function usdRate(): float
    {
        $r = DB::table('exchange_rates')->where('currency', 'USD')->orderByDesc('date')->value('rate_to_uzs');

        return $r ? (float) $r : 0.0;
    }

    /** Показатели менеджеров (§28). */
    public static function managers(array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! $ids) {
            return [];
        }
        $leads = DB::table('leads as l')->join('deal_stages as s', 's.id', '=', 'l.stage_id')->whereIn('l.owner_id', $ids)
            ->where('l.created_at', '>=', $from)->where('l.created_at', '<', $to)->whereNull('l.deleted_at')
            ->get(['l.owner_id', 'l.status', 'l.deal_id', 's.code']);
        $meetings = DB::table('meetings')->whereIn('manager_id', $ids)->where('status', 'DONE')->where('starts_at', '>=', $from)->where('starts_at', '<', $to)
            ->groupBy('manager_id')->select('manager_id', DB::raw('COUNT(*) n'))->pluck('n', 'manager_id');
        $proposals = DB::table('proposals')->whereIn('manager_id', $ids)->where('sent_at', '>=', $from)->where('sent_at', '<', $to)
            ->groupBy('manager_id')->select('manager_id', DB::raw('COUNT(*) n'))->pluck('n', 'manager_id');
        $contracts = DB::table('contracts as c')->join('deals as d', 'd.id', '=', 'c.deal_id')->where('c.status', 'SIGNED')
            ->where('c.signed_at', '>=', $from)->where('c.signed_at', '<', $to)->whereIn('d.owner_id', $ids)
            ->groupBy('d.owner_id')->select('d.owner_id', DB::raw('COUNT(*) n'))->pluck('n', 'owner_id');
        $payments = DB::table('payments as p')->join('deals as d', 'd.id', '=', 'p.deal_id')->where('p.status', 'PAID')
            ->where('p.paid_at', '>=', $from)->where('p.paid_at', '<', $to)->whereIn('d.owner_id', $ids)->get(['p.type', 'p.amount_uzs', 'd.owner_id']);
        $won = DB::table('deals')->whereIn('owner_id', $ids)->where('won_at', '>=', $from)->where('won_at', '<', $to)
            ->groupBy('owner_id')->select('owner_id', DB::raw('COUNT(*) n'))->pluck('n', 'owner_id');
        $out = [];
        foreach ($ids as $id) {
            $mine = $leads->where('owner_id', $id);
            $pays = $payments->where('owner_id', $id);
            $revenue = $pays->reduce(fn ($s, $p) => $p->type === 'REFUND' ? $s->minus($p->amount_uzs) : $s->plus($p->amount_uzs), BigDecimal::zero());
            $orders = (int) ($won[$id] ?? 0);
            $out[$id] = [
                'leads' => $mine->count(),
                'processedLeads' => $mine->filter(fn ($l) => $l->status !== 'OPEN' || $l->code !== 'NEW')->count(),
                'meetings' => (int) ($meetings[$id] ?? 0),
                'proposals' => (int) ($proposals[$id] ?? 0),
                'contracts' => (int) ($contracts[$id] ?? 0),
                'payments' => $pays->where('type', '<>', 'REFUND')->count(),
                'orders' => $orders,
                'revenueUzs' => (string) $revenue->toScale(2),
                'avgCheckUzs' => self::avg($revenue, $orders),
                'conversionPct' => self::pct($mine->whereNotNull('deal_id')->count(), $mine->count()),
            ];
        }

        return $out;
    }

    /** Показатели РОП — по отделу (§29). */
    public static function rop(array $teamIds, CarbonImmutable $from, CarbonImmutable $to, BigDecimal $plan): array
    {
        $teamIds = $teamIds ?: ['-'];
        $payments = DB::table('payments as p')->join('deals as d', 'd.id', '=', 'p.deal_id')->where('p.status', 'PAID')
            ->where('p.paid_at', '>=', $from)->where('p.paid_at', '<', $to)->whereIn('d.team_id', $teamIds)->get(['p.type', 'p.amount_uzs']);
        $orders = DB::table('deals')->whereIn('team_id', $teamIds)->where('won_at', '>=', $from)->where('won_at', '<', $to)->count();
        $leads = DB::table('leads')->whereIn('team_id', $teamIds)->where('created_at', '>=', $from)->where('created_at', '<', $to)->whereNull('deleted_at')->get(['deal_id']);
        $managers = DB::table('users as u')->join('roles as r', 'r.id', '=', 'u.role_id')->whereIn('u.team_id', $teamIds)
            ->whereNull('u.deleted_at')->where('u.status', 'ACTIVE')->where('r.code', 'MANAGER')->count();
        $fromDate = Tasks::companyDate($from);
        $toDate = Tasks::companyDate($to);
        $expenses = BigDecimal::of((string) (DB::table('expenses as e')->join('projects as p', 'p.id', '=', 'e.project_id')->whereNull('e.deleted_at')
            ->where('e.scope', 'PROJECT')->whereIn('p.team_id', $teamIds)->where('e.expense_date', '>=', $fromDate)->where('e.expense_date', '<', $toDate)
            ->sum('e.amount_uzs') ?: '0'));
        $projects = DB::table('projects')->whereIn('team_id', $teamIds)->whereNull('deleted_at')->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();
        $overdueTasks = DB::table('tasks as t')->join('projects as p', 'p.id', '=', 't.project_id')->whereNull('t.deleted_at')->whereNull('p.deleted_at')
            ->whereIn('p.team_id', $teamIds)->whereIn('t.status', ProjectAccess::OPEN_TASK)->where('t.deadline', '<', now())->count();
        $overdueProjects = DB::table('projects')->whereIn('team_id', $teamIds)->whereNull('deleted_at')->whereIn('status', ProjectAccess::ACTIVE)
            ->where('deadline', '<', Tasks::companyDate(now()))->count();
        $net = $payments->reduce(fn ($s, $p) => $p->type === 'REFUND' ? $s->minus($p->amount_uzs) : $s->plus($p->amount_uzs), BigDecimal::zero());
        $gross = $net->minus($expenses);

        return [
            'teamRevenueUzs' => (string) $net->toScale(2),
            'orders' => $orders,
            'avgCheckUzs' => self::avg($net, $orders),
            'conversionPct' => self::pct($leads->whereNotNull('deal_id')->count(), $leads->count()),
            'managers' => $managers,
            'planUzs' => (string) $plan->toScale(2),
            'planPct' => People::completionPct($net, $plan->isPositive() ? $plan : null),
            'marginPct' => Finance::marginPct($gross, $net),
            'projects' => $projects,
            'overdueTasks' => $overdueTasks,
            'overdueProjects' => $overdueProjects,
        ];
    }

    /** Показатели исполнителей (§30). */
    public static function executors(array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! $ids) {
            return [];
        }
        $now = CarbonImmutable::now();
        $until = $to < $now ? $to : $now;
        $tasks = DB::table('tasks')->whereIn('assignee_id', $ids)->whereNull('deleted_at')->where('status', '<>', 'CANCELLED')->where('created_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('completed_at')->orWhere('completed_at', '>=', $from))
            ->get(['assignee_id', 'status', 'deadline', 'started_at', 'completed_at']);
        $reworks = DB::table('task_status_history as h')->join('tasks as t', 't.id', '=', 'h.task_id')->where('h.from_status', 'REVIEW')
            ->whereIn('h.to_status', ['IN_PROGRESS', 'TODO'])->where('h.created_at', '>=', $from)->where('h.created_at', '<', $to)
            ->whereIn('t.assignee_id', $ids)->groupBy('t.assignee_id')->select('t.assignee_id', DB::raw('COUNT(*) n'))->pluck('n', 'assignee_id');
        $ts = fn ($v) => $v ? CarbonImmutable::parse($v, 'UTC') : null;
        $out = [];
        foreach ($ids as $id) {
            $mine = $tasks->where('assignee_id', $id);
            $done = $mine->filter(fn ($t) => $t->status === 'DONE' && $t->completed_at && $ts($t->completed_at) >= $from && $ts($t->completed_at) < $to);
            $overdue = $mine->filter(fn ($t) => $t->status === 'DONE'
                ? ($t->deadline && $t->completed_at && $ts($t->completed_at) > $ts($t->deadline) && $ts($t->completed_at) >= $from)
                : ($t->deadline && $ts($t->deadline) < $until))->count();
            $timed = $done->filter(fn ($t) => $t->started_at);
            $hours = $timed->sum(fn ($t) => ($ts($t->completed_at)->getTimestampMs() - $ts($t->started_at)->getTimestampMs()) / 3_600_000);
            $out[$id] = [
                'tasks' => $mine->count(),
                'done' => $done->count(),
                'overdue' => $overdue,
                'avgHours' => $timed->count() ? number_format($hours / $timed->count(), 1, '.', '') : null,
                'completionPct' => self::pct($done->count(), $mine->count()),
                'reworks' => (int) ($reworks[$id] ?? 0),
            ];
        }

        return $out;
    }

    /** Выполнение целей по фактам. */
    private static function progress(Collection $targets, array $facts, float $usd): array
    {
        return $targets->map(function ($t) use ($facts, $usd) {
            $raw = (float) ($facts[$t->metric] ?? 0);
            $fact = $t->metric === 'REVENUE' && $t->currency === 'USD' && $usd ? $raw / $usd : $raw;

            return [
                'metric' => $t->metric,
                'target' => (string) $t->target_value,
                'currency' => $t->currency,
                'fact' => number_format($fact, 2, '.', ''),
                'pct' => People::completionPct($fact, (string) $t->target_value),
            ];
        })->values()->all();
    }

    /**
     * Строки KPI для видимых сотрудников. Цели — на месяц $period; факт — за диапазон.
     *
     * @param  array{group?:?string,userIds?:?array,code?:?string}  $opts
     */
    public static function rows(string $period, CarbonImmutable $from, CarbonImmutable $to, array $opts = []): array
    {
        $people = self::users($opts['code'] ?? 'kpi.read')->where('users.status', 'ACTIVE')
            ->whereHas('role', fn ($q) => $q->whereIn('code', ! empty($opts['group']) ? [$opts['group']] : self::GROUPS))
            ->when($opts['userIds'] ?? null, fn ($q, $ids) => $q->whereIn('users.id', $ids))
            ->with(['role:id,code', 'team:id,name', 'headedTeams:id,head_id'])->orderBy('full_name')->get();

        return self::build($people, $period, $from, $to);
    }

    public static function build(Collection $people, string $period, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = fn (string $g) => $people->filter(fn ($p) => $p->role->code === $g)->pluck('id')->all();
        $mgr = self::managers($ids('MANAGER'), $from, $to);
        $exe = self::executors($ids('EXECUTOR'), $from, $to);
        $targets = KpiTarget::whereIn('user_id', $people->pluck('id'))->where('period', $period)->get();
        $usd = self::usdRate();
        $rows = [];
        foreach ($people as $p) {
            $group = $p->role->code;
            if (! in_array($group, self::GROUPS, true)) {
                continue;
            }
            $mine = $targets->where('user_id', $p->id);
            $row = ['user' => $p, 'role' => $group, 'team' => $p->team, 'manager' => null, 'rop' => null, 'executor' => null, 'targets' => [], 'kpiPct' => null];
            if ($group === 'MANAGER') {
                $k = $mgr[$p->id];
                $row['manager'] = $k;
                $facts = ['REVENUE' => (float) $k['revenueUzs'], 'ORDERS' => $k['orders'], 'LEADS' => $k['leads'], 'MEETINGS' => $k['meetings']];
            } elseif ($group === 'EXECUTOR') {
                $k = $exe[$p->id];
                $row['executor'] = $k;
                $facts = ['TASKS' => $k['done']];
            } else {
                $teamIds = $p->headedTeams->count() ? $p->headedTeams->pluck('id')->all() : ($p->team_id ? [$p->team_id] : []);
                $own = $mine->firstWhere('metric', 'REVENUE');
                $usdD = BigDecimal::of((string) $usd);
                if ($own) {
                    $plan = $own->currency === 'USD' ? BigDecimal::of($own->target_value)->multipliedBy($usdD) : BigDecimal::of($own->target_value);
                } else {
                    // План отдела — сумма целей менеджеров
                    $plan = KpiTarget::where('period', $period)->where('metric', 'REVENUE')
                        ->whereHas('user', fn ($q) => $q->whereIn('team_id', $teamIds ?: ['-'])->whereHas('role', fn ($r) => $r->where('code', 'MANAGER')))
                        ->get()->reduce(fn ($s, $t) => $s->plus($t->currency === 'USD' ? BigDecimal::of($t->target_value)->multipliedBy($usdD) : BigDecimal::of($t->target_value)), BigDecimal::zero());
                }
                $k = self::rop($teamIds, $from, $to, $plan);
                $row['rop'] = $k;
                $facts = [
                    'REVENUE' => (float) $k['teamRevenueUzs'],
                    'ORDERS' => $k['orders'],
                    'LEADS' => DB::table('leads')->whereIn('team_id', $teamIds ?: ['-'])->where('created_at', '>=', $from)->where('created_at', '<', $to)->whereNull('deleted_at')->count(),
                    'MEETINGS' => DB::table('meetings')->whereIn('team_id', $teamIds ?: ['-'])->where('status', 'DONE')->where('starts_at', '>=', $from)->where('starts_at', '<', $to)->count(),
                ];
            }
            $row['targets'] = self::progress($mine, $facts, $usd);
            $row['kpiPct'] = People::averagePct(array_column($row['targets'], 'pct'));
            $rows[] = $row;
        }

        return $rows;
    }

    /** Цели сотрудника на месяц (видимого по kpi.read, иначе 404). */
    public static function targets(string $userId, string $period): Collection
    {
        abort_unless(self::users('kpi.read')->whereKey($userId)->exists(), 404);

        return KpiTarget::where('user_id', $userId)->where('period', $period)->get();
    }
}
