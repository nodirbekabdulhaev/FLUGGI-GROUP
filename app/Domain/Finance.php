<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Support\Dec;
use Brick\Math\BigDecimal;

/**
 * Финансовые формулы (ТЗ §25, §27; docs/BUSINESS_RULES.md §4).
 * Все суммы — UZS, строки-decimal на выходе, чтобы не терять копейки.
 */
final class Finance
{
    /** Маржинальность %, 2 знака; null — выручки нет (делить не на что). */
    public static function marginPct(BigDecimal|string|int|float $profit, BigDecimal|string|int|float $revenue): ?string
    {
        $r = Dec::of($revenue);
        if ($r->isLessThanOrEqualTo(0)) {
            return null;
        }

        return Dec::toFixed(Dec::dp(Dec::mul(Dec::div($profit, $r), 100), 2), 2);
    }

    /**
     * Финансы проекта: валовая прибыль = выручка − расходы, маржа = прибыль / выручка.
     *
     * @return array{grossProfit: string, marginPct: ?string}
     */
    public static function projectFinance(BigDecimal|string|int|float $revenueUzs, BigDecimal|string|int|float $expensesUzs): array
    {
        $profit = Dec::sub($revenueUzs, $expensesUzs);

        return ['grossProfit' => Dec::toFixed($profit, 2), 'marginPct' => self::marginPct($profit, $revenueUzs)];
    }

    /**
     * Финансы компании за период.
     *  Gross Profit     = Collected − Refunds − проектные расходы
     *  Operating Profit = Gross Profit − расходы компании − комиссии + прочие поступления
     *  Margin %         = Gross Profit / (Collected − Refunds)
     * otherIncome — прочие поступления (не от клиентов).
     *
     * @param  array{collected: BigDecimal|string|int|float, refunds: BigDecimal|string|int|float, projectExpenses: BigDecimal|string|int|float, companyExpenses: BigDecimal|string|int|float, commissions: BigDecimal|string|int|float, otherIncome?: BigDecimal|string|int|float|null}  $x
     * @return array{grossProfit: string, operatingProfit: string, marginPct: ?string}
     */
    public static function companyFinance(array $x): array
    {
        $net = Dec::sub($x['collected'], $x['refunds']);
        $gross = Dec::sub($net, $x['projectExpenses']);
        $operating = Dec::add(
            Dec::sub(Dec::sub($gross, $x['companyExpenses']), $x['commissions']),
            $x['otherIncome'] ?? 0,
        );

        return [
            'grossProfit' => Dec::toFixed($gross, 2),
            'operatingProfit' => Dec::toFixed($operating, 2),
            'marginPct' => self::marginPct($gross, $net),
        ];
    }

    /**
     * Доля накладных (аренда, офис) на один проект за месяц:
     * накладные месяца ÷ (заданный делитель или число проектов, бывших в работе в этом месяце).
     */
    public static function overheadShare(BigDecimal|string|int|float $monthOverheadUzs, int|float $activeProjects, int|float|null $divisor = null): string
    {
        $n = $divisor && $divisor > 0 ? $divisor : max(1, $activeProjects);

        return Dec::toFixed(Dec::div($monthOverheadUzs, $n), 2);
    }
}
