<?php

namespace App\Services\Automation;

use App\Domain\Finance;
use App\Domain\People;
use App\Domain\Tasks;
use App\Support\Format;
use App\Support\Lang;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Отчёты руководителю (ТЗ §56–57). Тексты — для Telegram (HTML) и in-app; язык — получателя.
 * $teamIds — отделы для отчёта РОП; null — вся компания.
 */
final class ReportService
{
    public const ACTIVE_PROJECT = ['NEW', 'PLANNING', 'IN_PROGRESS', 'REVIEW', 'WAITING_CLIENT', 'PAUSED'];

    public const OPEN_TASK = ['TODO', 'IN_PROGRESS', 'REVIEW', 'BLOCKED'];

    private static function ts(DateTimeInterface $d): string
    {
        return CarbonImmutable::instance($d)->utc()->format('Y-m-d H:i:s.v');
    }

    private static function team(Builder $q, ?array $teamIds, string $col = 'team_id'): Builder
    {
        return $teamIds === null ? $q : $q->whereIn($col, $teamIds ?: ['-']);
    }

    private static function dealIds(?array $teamIds): ?Builder
    {
        return $teamIds === null ? null : DB::table('deals')->whereIn('team_id', $teamIds ?: ['-'])->select('id');
    }

    /** Оплаты за период: итог за вычетом возвратов и рейтинг менеджеров. */
    public static function collected(?array $teamIds, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $rows = DB::table('payments as p')->join('deals as d', 'd.id', '=', 'p.deal_id')->join('users as u', 'u.id', '=', 'd.owner_id')
            ->where('p.status', 'PAID')->where('p.paid_at', '>=', self::ts($from))->where('p.paid_at', '<', self::ts($to))
            ->when($teamIds !== null, fn ($q) => $q->whereIn('d.team_id', $teamIds ?: ['-']))
            ->get(['p.type', 'p.amount_uzs', 'u.full_name']);
        $net = BigDecimal::zero();
        $by = [];
        foreach ($rows as $p) {
            $v = BigDecimal::of($p->amount_uzs);
            $signed = $p->type === 'REFUND' ? $v->negated() : $v;
            $net = $net->plus($signed);
            $by[$p->full_name] = ($by[$p->full_name] ?? BigDecimal::zero())->plus($signed);
        }
        uasort($by, fn ($a, $b) => $b->compareTo($a));

        return ['net' => $net, 'ranking' => $by];
    }

    /** План месяца: сумма целей «Выручка» менеджеров (UZS; USD по текущему курсу). */
    public static function monthPlan(?array $teamIds, string $period): BigDecimal
    {
        $targets = DB::table('kpi_targets as k')->join('users as u', 'u.id', '=', 'k.user_id')->join('roles as r', 'r.id', '=', 'u.role_id')
            ->where('k.period', $period)->where('k.metric', 'REVENUE')->where('r.code', 'MANAGER')
            ->when($teamIds !== null, fn ($q) => $q->whereIn('u.team_id', $teamIds ?: ['-']))
            ->get(['k.target_value', 'k.currency']);
        $rate = BigDecimal::of((string) (DB::table('exchange_rates')->where('currency', 'USD')->orderByDesc('date')->value('rate_to_uzs') ?? 0));
        $sum = BigDecimal::zero();
        foreach ($targets as $t) {
            $v = BigDecimal::of($t->target_value);
            $sum = $sum->plus($t->currency === 'USD' ? $v->multipliedBy($rate) : $v);
        }

        return $sum;
    }

    /** Прогноз месяца: получено + взвешенная воронка открытых сделок (ТЗ §40). */
    private static function forecast(?array $teamIds, BigDecimal $collectedMonth): BigDecimal
    {
        $open = self::team(DB::table('deals as d')->join('deal_stages as s', 's.id', '=', 'd.stage_id')
            ->where('d.status', 'OPEN')->whereNull('d.deleted_at'), $teamIds, 'd.team_id')
            ->get(['d.amount_uzs', 'd.probability_override', 's.probability']);
        $w = BigDecimal::zero();
        foreach ($open as $d) {
            $w = $w->plus(BigDecimal::of($d->amount_uzs)->multipliedBy($d->probability_override ?? $d->probability)->dividedBy(100, 6, RoundingMode::HalfUp));
        }

        return $collectedMonth->plus($w);
    }

    /** @return array{period:string,plan:BigDecimal,collected:BigDecimal} */
    public static function planProgress(?array $teamIds, ?DateTimeInterface $now = null): array
    {
        $now ??= now();
        $period = substr(Tasks::companyDate($now), 0, 7);
        $m = People::monthRange($period);

        return ['period' => $period, 'plan' => self::monthPlan($teamIds, $period), 'collected' => self::collected($teamIds, $m['from'], $m['to'])['net']];
    }

    /** «187,4 млн» / «950 000». */
    private static function mln(BigDecimal $v): string
    {
        if ($v->abs()->isGreaterThanOrEqualTo(1_000_000)) {
            return Format::number($v->dividedBy(1_000_000, 1, RoundingMode::HalfUp), 1).' '.t('common.units.mln');
        }

        return Format::number($v->toScale(0, RoundingMode::HalfUp), 0);
    }

    private static function uzs(BigDecimal $v): string
    {
        return Format::number($v->toScale(0, RoundingMode::HalfUp), 0).' UZS';
    }

    /** «9 ОКТЯБРЯ» */
    private static function dayTitle(DateTimeInterface $d): string
    {
        $local = Format::local($d);

        return mb_strtoupper($local->day.' '.t('automation.report.months.'.$local->month));
    }

    private static function count(Builder $q): int
    {
        return (int) $q->count();
    }

    /** Ежедневный отчёт за день (ТЗ §56). */
    public static function daily(?array $teamIds, ?DateTimeInterface $now = null, ?string $locale = null): string
    {
        return self::inLocale($locale, function () use ($teamIds, $now) {
            $now ??= now();
            $date = Tasks::companyDate($now);
            $from = Tasks::companyDayStart($date);
            $to = $from->addDay();
            $in = fn (Builder $q, string $col) => $q->where($col, '>=', self::ts($from))->where($col, '<', self::ts($to));
            $deal = self::dealIds($teamIds);
            $byDeal = fn (Builder $q) => $deal ? $q->whereIn('deal_id', $deal) : $q;

            $leads = self::count($in(self::team(DB::table('leads')->whereNull('deleted_at'), $teamIds), 'created_at'));
            $qualified = self::count($in(self::team(DB::table('leads'), $teamIds), 'converted_at'));
            $meetings = self::count($in(self::team(DB::table('meetings')->where('status', 'DONE'), $teamIds), 'starts_at'));
            $proposals = self::count($byDeal($in(DB::table('proposals'), 'sent_at')));
            $contracts = self::count($byDeal($in(DB::table('contracts')->where('status', 'SIGNED'), 'signed_at')));
            $day = self::collected($teamIds, $from, $to);
            $projects = self::count($in(self::team(DB::table('projects')->whereNull('deleted_at'), $teamIds), 'created_at'));
            $overdueTasks = self::count(self::overdueTasks($teamIds, $now));
            $overdueProjects = self::count(self::team(DB::table('projects')->whereNull('deleted_at')
                ->whereIn('status', self::ACTIVE_PROJECT)->where('deadline', '<', $date), $teamIds));
            $month = self::planProgress($teamIds, $now);
            $forecast = self::forecast($teamIds, $month['collected']);
            $bestName = array_key_first($day['ranking']);
            $planPct = $month['plan']->isGreaterThan(0)
                ? $month['collected']->multipliedBy(100)->dividedBy($month['plan'], 0, RoundingMode::HalfUp).'%'
                : t('automation.report.noPlan');

            return implode("\n", [
                '📊 <b>'.t('automation.report.dailyTitle', ['date' => self::dayTitle($now)]).'</b>',
                '',
                t('automation.report.newLeads').': '.$leads,
                t('automation.report.qualified').': '.$qualified,
                t('automation.report.meetings').': '.$meetings,
                t('automation.report.proposals').': '.$proposals,
                t('automation.report.contracts').': '.$contracts,
                t('automation.report.payments').': '.self::uzs($day['net']),
                t('automation.report.newProjects').': '.$projects,
                t('automation.report.overdueTasks').': '.$overdueTasks,
                t('automation.report.overdueProjects').': '.$overdueProjects,
                '',
                t('automation.report.bestManager').': '.($bestName !== null ? $bestName.' — '.self::mln($day['ranking'][$bestName]) : '—'),
                t('automation.report.planMonth').': '.$planPct,
                t('automation.report.forecast').': '.self::mln($forecast),
            ]);
        });
    }

    private static function overdueTasks(?array $teamIds, DateTimeInterface $now): Builder
    {
        return DB::table('tasks as t')->join('projects as p', 'p.id', '=', 't.project_id')
            ->whereNull('t.deleted_at')->whereNull('p.deleted_at')->whereIn('t.status', self::OPEN_TASK)
            ->where('t.deadline', '<', self::ts($now))
            ->when($teamIds !== null, fn ($q) => $q->whereIn('p.team_id', $teamIds ?: ['-']));
    }

    /** Еженедельный отчёт за 7 дней (ТЗ §57). */
    public static function weekly(?array $teamIds, ?DateTimeInterface $now = null, ?string $locale = null): string
    {
        return self::inLocale($locale, function () use ($teamIds, $now) {
            $now ??= now();
            $to = Tasks::companyDayStart(Tasks::companyDate($now));
            $from = $to->subDays(7);
            $in = fn (Builder $q, string $col) => $q->where($col, '>=', self::ts($from))->where($col, '<', self::ts($to));
            // Календарные даты расходов: [from; to) по Ташкенту
            $dFrom = Tasks::companyDate($from);
            $dTo = Tasks::companyDate($to);

            $leads = self::count($in(self::team(DB::table('leads')->whereNull('deleted_at'), $teamIds), 'created_at'));
            $converted = self::count($in(self::team(DB::table('leads')->whereNull('deleted_at')->whereNotNull('deal_id'), $teamIds), 'created_at'));
            $deals = self::count($in(self::team(DB::table('deals')->whereNull('deleted_at'), $teamIds), 'created_at'));
            $won = self::count($in(self::team(DB::table('deals'), $teamIds), 'won_at'));
            $money = self::collected($teamIds, $from, $to);
            $projectExp = BigDecimal::of((string) (DB::table('expenses as e')->join('projects as p', 'p.id', '=', 'e.project_id')
                ->whereNull('e.deleted_at')->where('e.scope', 'PROJECT')->where('e.expense_date', '>=', $dFrom)->where('e.expense_date', '<', $dTo)
                ->when($teamIds !== null, fn ($q) => $q->whereIn('p.team_id', $teamIds ?: ['-']))->sum('e.amount_uzs') ?: 0));
            $companyExp = $teamIds !== null ? BigDecimal::zero() : BigDecimal::of((string) (DB::table('expenses')->whereNull('deleted_at')
                ->where('scope', 'COMPANY')->where('expense_date', '>=', $dFrom)->where('expense_date', '<', $dTo)->sum('amount_uzs') ?: 0));
            $projects = self::count($in(self::team(DB::table('projects')->whereNull('deleted_at'), $teamIds), 'created_at'));
            $overdueTasks = self::count(self::overdueTasks($teamIds, $now));

            $expenses = $projectExp->plus($companyExp);
            $profit = $money['net']->minus($projectExp);
            $avg = $won > 0 ? $money['net']->dividedBy($won, 2, RoundingMode::HalfUp) : BigDecimal::zero();
            $conv = $leads > 0 ? round($converted / $leads * 100).'%' : '—';
            $margin = Finance::marginPct((string) $profit, (string) $money['net']);
            $top = [];
            $i = 0;
            foreach (array_slice($money['ranking'], 0, 3, true) as $name => $v) {
                $top[] = (++$i).'. '.$name.' — '.self::mln($v);
            }

            return implode("\n", [
                '📈 <b>'.t('automation.report.weeklyTitle').'</b> ('.self::dayTitle($from).' — '.self::dayTitle($to->subDay()).')',
                '',
                t('automation.report.leads').': '.$leads,
                t('automation.report.conversion').': '.$conv,
                t('automation.report.deals').': '.$deals,
                t('automation.report.revenue').': '.self::uzs($money['net']),
                t('automation.report.avgCheck').': '.self::uzs($avg),
                t('automation.report.profit').': '.self::uzs($profit).($margin ? ' ('.$margin.'%)' : ''),
                t('automation.report.expenses').': '.self::uzs($expenses),
                t('automation.report.projects').': '.$projects,
                t('automation.report.overdueTasks').': '.$overdueTasks,
                '',
                t('automation.report.efficiency').':',
                ...($top ?: ['—']),
            ]);
        });
    }

    private static function inLocale(?string $locale, callable $fn): string
    {
        if ($locale === null || ! in_array($locale, Lang::LOCALES, true)) {
            return $fn();
        }
        $prev = app()->getLocale();
        app()->setLocale($locale);
        try {
            return $fn();
        } finally {
            app()->setLocale($prev);
        }
    }
}
