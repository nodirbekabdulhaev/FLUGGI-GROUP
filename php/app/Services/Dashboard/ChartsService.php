<?php

namespace App\Services\Dashboard;

use App\Auth\Access;
use App\Domain\Forecast;
use App\Domain\Tasks;
use App\Models\DealStage;
use App\Services\Analytics\AnalyticsService;
use App\Services\Crm\CrmAccess;
use App\Services\Finance\FinanceService;
use App\Services\Smm\SmmAccess;
use App\Support\Lang;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Данные графиков главной. Только из существующих выборок и в области видимости пользователя:
 * продажи и источники — analytics.read (CEO — компания, РОП — отдел, менеджер — свои),
 * сделки по этапам — deal.read, расходы по категориям — finance.read, ролики SMM — доступ к разделу SMM.
 * Нет права — блока нет (null); нет данных — пустой ряд (график показывает пустое состояние).
 */
final class ChartsService
{
    public const TZ = 'Asia/Tashkent';

    public static function get(Access $a, array $period, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $analytics = $a->can('analytics.read');

        return [
            'sales' => $analytics ? self::sales12($now) : null,
            'sources' => $analytics ? self::sources($period) : null,
            'stages' => $a->can('deal.read') ? self::stages() : null,
            'plan' => $analytics ? self::planFact($now) : null,
            'expenses' => $a->can('finance.read') ? self::expenses($period) : null,
            'smm' => SmmAccess::allowed() ? self::smmMonthly($now) : null,
        ];
    }

    /** Последние 12 месяцев, включая текущий: [from, to) по Ташкенту в UTC и ключи YYYY-MM. */
    public static function last12(CarbonImmutable $now): array
    {
        $start = $now->setTimezone(self::TZ)->startOfMonth()->subMonthsNoOverflow(11);
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[] = $start->addMonthsNoOverflow($i)->format('Y-m');
        }

        return [$start->utc(), $start->addMonthsNoOverflow(12)->utc(), $months];
    }

    public static function monthLabel(string $ym): string
    {
        return t('analytics.monthsShort.'.(int) substr($ym, 5, 2)).' '.substr($ym, 2, 2);
    }

    /** Выручка (подписанные договоры) и получено (оплаты минус возвраты) по месяцам. */
    public static function sales12(CarbonImmutable $now): array
    {
        [$from, $to, $months] = self::last12($now);
        $points = collect((new AnalyticsService)->sales($from, $to)['points'])->keyBy('key');
        $revenue = [];
        $collected = [];
        foreach ($months as $m) {
            $revenue[] = $points[$m]['revenue'] ?? '0.00';
            $collected[] = $points[$m]['collected'] ?? '0.00';
        }
        $sum = fn (array $xs) => (string) array_reduce($xs, fn ($s, $v) => $s->plus($v), BigDecimal::zero())->toScale(2);

        return [
            'months' => $months,
            'labels' => array_map(self::monthLabel(...), $months),
            'revenue' => $revenue,
            'collected' => $collected,
            'revenueTotal' => $sum($revenue),
            'collectedTotal' => $sum($collected),
            'empty' => ! array_filter([...$revenue, ...$collected], fn ($v) => ! BigDecimal::of($v)->isZero()),
        ];
    }

    /** Лиды периода по источникам (число лидов, как в аналитике). */
    public static function sources(array $period): array
    {
        $rows = (new AnalyticsService)->breakdown($period['from'], $period['to'], 'source');
        usort($rows, fn ($x, $y) => $y['leads'] <=> $x['leads']);

        return [
            'rows' => array_map(fn ($r) => ['name' => $r['name'], 'leads' => $r['leads'], 'won' => $r['won'], 'conversion' => $r['conversion']], $rows),
            'total' => array_sum(array_column($rows, 'leads')),
        ];
    }

    /** Открытые сделки по этапам воронки: число, сумма (UZS) и доля от всех открытых. */
    public static function stages(): array
    {
        $agg = CrmAccess::deals()->where('deals.status', 'OPEN')->groupBy('deals.stage_id')
            ->selectRaw('deals.stage_id, COUNT(*) AS n, SUM(deals.amount_uzs) AS s')->get()->keyBy('stage_id');
        $total = (int) $agg->sum('n');
        $rows = [];
        foreach (DealStage::where('entity', 'DEAL')->orderBy('sort')->get() as $st) {
            $r = $agg[$st->id] ?? null;
            if (! $r && $st->code === 'PAID') {
                continue;
            }
            $rows[] = [
                'id' => $st->id,
                'name' => Lang::name($st),
                'count' => (int) ($r->n ?? 0),
                'amountUzs' => (string) BigDecimal::of((string) ($r->s ?? '0'))->toScale(2),
                'pct' => Forecast::conversion((int) ($r->n ?? 0), $total),
            ];
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'amountUzs' => (string) array_reduce($rows, fn ($s, $r) => $s->plus($r['amountUzs']), BigDecimal::zero())->toScale(2),
        ];
    }

    /** План месяца (цели «Выручка» в области видимости) и получено за месяц. */
    public static function planFact(CarbonImmutable $now): ?array
    {
        $m = (new AnalyticsService)->forecast($now)['months'][0];
        if (! $m['plan'] || ! BigDecimal::of($m['plan'])->isPositive()) {
            return null;
        }
        $pct = BigDecimal::of($m['collected'])->multipliedBy(100)->dividedBy($m['plan'], 1, RoundingMode::HalfUp);

        return ['month' => $m['month'], 'plan' => $m['plan'], 'fact' => $m['collected'], 'forecast' => $m['total'], 'pct' => (string) $pct];
    }

    /** Расходы периода по категориям (как в финансовом дашборде). */
    public static function expenses(array $period): array
    {
        $rows = FinanceService::summary($period)['expensesByCategory'];
        $rows = array_values(array_filter($rows, fn ($r) => BigDecimal::of($r['amountUzs'])->isPositive()));

        return [
            'rows' => $rows,
            'total' => (string) array_reduce($rows, fn ($s, $r) => $s->plus($r['amountUzs']), BigDecimal::zero())->toScale(2),
        ];
    }

    /** Опубликованные ролики SMM по месяцам (в области видимости раздела SMM). */
    public static function smmMonthly(CarbonImmutable $now): array
    {
        [$from, $to, $months] = self::last12($now);
        $counts = array_fill_keys($months, 0);
        foreach (SmmAccess::ideas()->where('status', 'PUBLISHED')->where('published_at', '>=', $from)->where('published_at', '<', $to)->pluck('published_at') as $at) {
            $k = substr(Tasks::companyDate($at), 0, 7);
            if (isset($counts[$k])) {
                $counts[$k]++;
            }
        }

        return [
            'months' => $months,
            'labels' => array_map(self::monthLabel(...), $months),
            'counts' => array_values($counts),
            'total' => array_sum($counts),
        ];
    }
}
