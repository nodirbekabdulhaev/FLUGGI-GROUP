<?php

namespace App\Services\Analytics;

use App\Domain\Analytics;
use App\Domain\Forecast;
use App\Domain\People;
use App\Domain\Tasks;
use App\Services\Crm\CrmAccess;
use App\Support\Lang;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Аналитика продаж (ТЗ §39–42). Все выборки ограничены правом analytics.read:
 * CEO — вся компания, РОП — свои отделы, менеджер — свои сделки и лиды.
 * Фильтр по отделу / сотруднику сужает, но не расширяет область видимости.
 */
final class AnalyticsService
{
    public function __construct(private ?string $teamId = null, private ?string $userId = null) {}

    public function deals(): Builder
    {
        return CrmAccess::deals('analytics.read')
            ->when($this->teamId, fn ($q) => $q->where('deals.team_id', $this->teamId))
            ->when($this->userId, fn ($q) => $q->where('deals.owner_id', $this->userId));
    }

    public function leads(): Builder
    {
        return CrmAccess::leads('analytics.read')
            ->when($this->teamId, fn ($q) => $q->where('leads.team_id', $this->teamId))
            ->when($this->userId, fn ($q) => $q->where('leads.owner_id', $this->userId));
    }

    private function dealIds()
    {
        return $this->deals()->select('deals.id');
    }

    private static function s(BigDecimal $v): string
    {
        return (string) $v->toScale(2, RoundingMode::HalfUp);
    }

    private static function pctChange(float $now, float $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 1000) / 10 : null;
    }

    private static function net(iterable $rows): BigDecimal
    {
        $acc = BigDecimal::zero();
        foreach ($rows as $p) {
            $acc = $p->type === 'REFUND' ? $acc->minus($p->amount_uzs) : $acc->plus($p->amount_uzs);
        }

        return $acc;
    }

    private function totals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $a = Period::ts($from);
        $b = Period::ts($to);

        return [
            'contracts' => DB::table('contracts')->where('status', 'SIGNED')->where('signed_at', '>=', $a)->where('signed_at', '<', $b)
                ->whereIn('deal_id', $this->dealIds())->get(['signed_at', 'amount_uzs']),
            'payments' => DB::table('payments')->where('status', 'PAID')->where('paid_at', '>=', $a)->where('paid_at', '<', $b)
                ->whereIn('deal_id', $this->dealIds())->get(['paid_at', 'type', 'amount_uzs']),
            'won' => $this->deals()->where('won_at', '>=', $a)->where('won_at', '<', $b)->pluck('won_at'),
            'leads' => $this->leads()->where('leads.created_at', '>=', $a)->where('leads.created_at', '<', $b)->pluck('leads.created_at'),
        ];
    }

    /** Динамика продаж по дням / неделям / месяцам и сравнение с прошлым периодом. */
    public function sales(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $g = Analytics::granularityFor($from, $to);
        $prevFrom = $from->subSeconds($to->getTimestamp() - $from->getTimestamp());
        $cur = $this->totals($from, $to);
        $prev = $this->totals($prevFrom, $from);
        $points = [];
        foreach (Analytics::bucketKeys($from, $to, $g) as $k) {
            $points[$k] = ['key' => $k, 'revenue' => BigDecimal::zero(), 'collected' => BigDecimal::zero(), 'won' => 0, 'leads' => 0];
        }
        $key = fn ($d) => $d ? Analytics::bucketKey(CarbonImmutable::parse($d, 'UTC'), $g) : null;
        foreach ($cur['contracts'] as $c) {
            if (isset($points[$k = $key($c->signed_at)])) {
                $points[$k]['revenue'] = $points[$k]['revenue']->plus($c->amount_uzs);
            }
        }
        foreach ($cur['payments'] as $p) {
            if (isset($points[$k = $key($p->paid_at)])) {
                $points[$k]['collected'] = $p->type === 'REFUND' ? $points[$k]['collected']->minus($p->amount_uzs) : $points[$k]['collected']->plus($p->amount_uzs);
            }
        }
        foreach ($cur['won'] as $d) {
            if (isset($points[$k = $key($d)])) {
                $points[$k]['won']++;
            }
        }
        foreach ($cur['leads'] as $d) {
            if (isset($points[$k = $key($d)])) {
                $points[$k]['leads']++;
            }
        }
        $sum = fn ($rows) => array_reduce($rows->all(), fn ($s, $c) => $s->plus($c->amount_uzs), BigDecimal::zero());
        $revenue = $sum($cur['contracts']);
        $collected = self::net($cur['payments']);
        $prevRevenue = $sum($prev['contracts']);

        return [
            'granularity' => $g,
            'points' => array_values(array_map(fn ($p) => ['key' => $p['key'], 'revenue' => self::s($p['revenue']), 'collected' => self::s($p['collected']), 'won' => $p['won'], 'leads' => $p['leads']], $points)),
            'totals' => [
                'revenue' => self::s($revenue),
                'collected' => self::s($collected),
                'won' => $cur['won']->count(),
                'leads' => $cur['leads']->count(),
                'avgCheck' => self::s($cur['contracts']->count() ? $revenue->dividedBy($cur['contracts']->count(), 2, RoundingMode::HalfUp) : BigDecimal::zero()),
            ],
            'change' => [
                'revenue' => self::pctChange($revenue->toFloat(), $prevRevenue->toFloat()),
                'collected' => self::pctChange($collected->toFloat(), self::net($prev['payments'])->toFloat()),
                'won' => self::pctChange($cur['won']->count(), $prev['won']->count()),
                'leads' => self::pctChange($cur['leads']->count(), $prev['leads']->count()),
            ],
        ];
    }

    /** Воронка (ТЗ §39): лиды, созданные в периоде, и как далеко они дошли. */
    public function funnel(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $base = fn () => $this->leads()->where('leads.created_at', '>=', Period::ts($from))->where('leads.created_at', '<', Period::ts($to));
        $meetingDone = fn ($q) => $q->where('status', 'DONE');
        $leads = $base()->count();
        $qualified = $base()->whereNotNull('deal_id')->count();
        $meetings = $base()->where(fn ($q) => $q->whereHas('meetings', $meetingDone)->orWhereHas('deal', fn ($d) => $d->withTrashed()->whereHas('meetings', $meetingDone)))->count();
        $proposals = $base()->whereHas('deal', fn ($d) => $d->withTrashed()->whereHas('proposals', fn ($p) => $p->whereNotNull('sent_at')))->count();
        $contracts = $base()->whereHas('deal', fn ($d) => $d->withTrashed()->whereHas('contracts', fn ($c) => $c->where('status', 'SIGNED')))->count();
        $paid = $base()->whereHas('deal', fn ($d) => $d->withTrashed()->whereHas('payments', fn ($p) => $p->where('status', 'PAID')->where('type', '<>', 'REFUND')))->count();
        $steps = [['leads', $leads], ['qualified', $qualified], ['meetings', $meetings], ['proposals', $proposals], ['contracts', $contracts], ['paid', $paid]];
        $out = [];
        foreach ($steps as $i => [$k, $n]) {
            $out[] = [
                'key' => $k,
                'label' => t('analytics.funnelSteps.'.$k),
                'count' => $n,
                'fromPrev' => $i === 0 ? 100 : Forecast::conversion($n, $steps[$i - 1][1]),
                'fromStart' => Forecast::conversion($n, $leads),
            ];
        }

        return $out;
    }

    private static function refName(?object $ref): string
    {
        if (! $ref) {
            return t('analytics.notSet');
        }

        return Lang::name($ref);
    }

    /** Лиды периода по источникам (ТЗ §42) или по услугам. */
    public function breakdown(CarbonImmutable $from, CarbonImmutable $to, string $by): array
    {
        $leads = $this->leads()->where('leads.created_at', '>=', Period::ts($from))->where('leads.created_at', '<', Period::ts($to))
            ->with(['source', 'service', 'deal' => fn ($q) => $q->withTrashed()->with(['payments' => fn ($p) => $p->where('status', 'PAID')])])
            ->get();
        $rows = [];
        foreach ($leads as $l) {
            $ref = $by === 'source' ? $l->source : $l->service;
            $key = $ref?->id ?? 'none';
            $rows[$key] ??= ['id' => $ref?->id, 'name' => self::refName($ref), 'leads' => 0, 'deals' => 0, 'won' => 0, 'revenue' => BigDecimal::zero()];
            $rows[$key]['leads']++;
            if ($l->deal) {
                $rows[$key]['deals']++;
                $paid = self::net($l->deal->payments);
                if ($paid->isGreaterThan(0)) {
                    $rows[$key]['won']++;
                    $rows[$key]['revenue'] = $rows[$key]['revenue']->plus($paid);
                }
            }
        }
        $out = array_map(fn ($r) => [
            'id' => $r['id'], 'name' => $r['name'], 'leads' => $r['leads'], 'deals' => $r['deals'], 'won' => $r['won'],
            'conversion' => Forecast::conversion($r['won'], $r['leads']),
            'revenue' => self::s($r['revenue']),
            'avgCheck' => self::s($r['won'] ? $r['revenue']->dividedBy($r['won'], 2, RoundingMode::HalfUp) : BigDecimal::zero()),
        ], array_values($rows));
        usort($out, fn ($a, $b) => BigDecimal::of($b['revenue'])->compareTo($a['revenue']) ?: $b['leads'] <=> $a['leads']);

        return $out;
    }

    /** Причины потерь (ТЗ §39): лиды и сделки, закрытые в периоде. */
    public function losses(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $a = Period::ts($from);
        $b = Period::ts($to);
        $leads = $this->leads()->whereIn('leads.status', ['LOST', 'REJECTED'])->where('closed_at', '>=', $a)->where('closed_at', '<', $b)
            ->groupBy('loss_reason_id')->selectRaw('loss_reason_id, COUNT(*) AS n')->pluck('n', 'loss_reason_id');
        $deals = $this->deals()->whereIn('deals.status', ['LOST', 'REJECTED'])->where('closed_at', '>=', $a)->where('closed_at', '<', $b)
            ->groupBy('loss_reason_id')->selectRaw('loss_reason_id, COUNT(*) AS n, SUM(amount_uzs) AS s')->get();
        $reasons = DB::table('loss_reasons')->get(['id', 'name_ru', 'name_uz'])->keyBy('id');
        $rows = [];
        $get = function (?string $id) use (&$rows, $reasons) {
            $k = $id ?? 'none';
            $rows[$k] ??= ['id' => $id, 'name' => self::refName($id ? $reasons->get($id) : null), 'leads' => 0, 'deals' => 0, 'amount' => '0.00'];

            return $k;
        };
        foreach ($leads as $id => $n) {
            $rows[$get($id ?: null)]['leads'] = (int) $n;
        }
        foreach ($deals as $d) {
            $k = $get($d->loss_reason_id);
            $rows[$k]['deals'] = (int) $d->n;
            $rows[$k]['amount'] = self::s(BigDecimal::of((string) ($d->s ?? 0)));
        }
        $out = array_values($rows);
        usort($out, fn ($x, $y) => ($y['leads'] + $y['deals']) <=> ($x['leads'] + $x['deals']));

        return $out;
    }

    /** План месяца по целям «Выручка» менеджеров в области видимости (USD — по последнему курсу). */
    private function plan(string $month): ?BigDecimal
    {
        $a = access();
        $scope = $a->scope('analytics.read');
        $q = DB::table('kpi_targets as k')->join('users as u', 'u.id', '=', 'k.user_id')->join('roles as r', 'r.id', '=', 'u.role_id')
            ->where('k.period', $month)->where('k.metric', 'REVENUE')->where('r.code', 'MANAGER');
        if ($this->userId) {
            $q->where('u.id', $this->userId);
            if ($scope === 'OWN' && $this->userId !== $a->id()) {
                return null;
            }
        } elseif ($scope === 'ALL') {
            $q->when($this->teamId, fn ($w) => $w->where('u.team_id', $this->teamId));
        } elseif ($scope === 'TEAM') {
            $q->whereIn('u.team_id', $this->teamId ? [$this->teamId] : ($a->teamIds() ?: ['-']));
        } else {
            $q->where('u.id', $a->id());
        }
        $targets = $q->get(['k.target_value', 'k.currency']);
        if ($targets->isEmpty()) {
            return null;
        }
        $rate = BigDecimal::of((string) (DB::table('exchange_rates')->where('currency', 'USD')->orderByDesc('date')->value('rate_to_uzs') ?? 0));

        return array_reduce($targets->all(), fn ($s, $t) => $s->plus($t->currency === 'USD' ? BigDecimal::of($t->target_value)->multipliedBy($rate) : $t->target_value), BigDecimal::zero());
    }

    /**
     * Прогноз (ТЗ §40) на текущий и 2 следующих месяца: получено + ожидаемые оплаты по графику +
     * открытые сделки × вероятность. Сделки, по которым уже стоят ожидаемые оплаты, во взвешенную
     * воронку не входят — без двойного счёта.
     */
    public function forecast(?\DateTimeInterface $now = null): array
    {
        $now ??= now();
        $first = substr(Tasks::companyDate($now), 0, 7);
        $months = [];
        $m = CarbonImmutable::createFromFormat('!Y-m', $first, 'UTC');
        for ($i = 0; $i < 3; $i++) {
            $months[] = $m->addMonthsNoOverflow($i)->format('Y-m');
        }
        $start = People::monthRange($months[0])['from'];
        $end = People::monthRange($months[2])['to'];
        $paid = DB::table('payments')->where('status', 'PAID')->where('paid_at', '>=', Period::ts($start))->where('paid_at', '<', Period::ts($end))
            ->whereIn('deal_id', $this->dealIds())->get(['paid_at', 'type', 'amount_uzs']);
        $pending = DB::table('payments')->where('status', 'PENDING')->where('type', '<>', 'REFUND')
            ->whereIn('deal_id', $this->dealIds())->get(['due_date', 'amount_uzs']);
        $open = $this->deals()->where('deals.status', 'OPEN')->with('stage:id,probability')
            ->withCount(['payments as pending_payments' => fn ($q) => $q->where('status', 'PENDING')])
            ->get(['deals.id', 'amount_uzs', 'expected_close_date', 'probability_override', 'stage_id']);
        $monthOfDate = function ($d) use ($first) {
            if (! $d) {
                return $first;
            }
            $mm = substr(is_string($d) ? $d : $d->format('Y-m-d'), 0, 7);

            return strcmp($mm, $first) < 0 ? $first : $mm;
        };
        $out = [];
        foreach ($months as $month) {
            $collected = self::net($paid->filter(fn ($p) => $p->paid_at && substr(Tasks::companyDate(CarbonImmutable::parse($p->paid_at, 'UTC')), 0, 7) === $month));
            $scheduled = array_reduce($pending->filter(fn ($p) => $monthOfDate($p->due_date) === $month)->all(), fn ($s, $p) => $s->plus($p->amount_uzs), BigDecimal::zero());
            $weighted = BigDecimal::zero();
            foreach ($open as $d) {
                if ($d->pending_payments === 0 && $monthOfDate($d->expected_close_date) === $month) {
                    $weighted = $weighted->plus(BigDecimal::of((string) $d->amount_uzs)->multipliedBy($d->probability_override ?? $d->stage->probability)->dividedBy(100, 6, RoundingMode::HalfUp));
                }
            }
            $plan = $this->plan($month);
            $out[] = [
                'month' => $month,
                'collected' => self::s($collected),
                'scheduled' => self::s($scheduled),
                'weighted' => self::s($weighted),
                'total' => self::s($collected->plus($scheduled)->plus($weighted)),
                'plan' => $plan ? self::s($plan) : null,
            ];
        }
        $pipeline = array_reduce($open->all(), fn ($s, $d) => $s->plus((string) $d->amount_uzs), BigDecimal::zero());

        return ['months' => $out, 'pipeline' => self::s($pipeline), 'openDeals' => $open->count()];
    }
}
