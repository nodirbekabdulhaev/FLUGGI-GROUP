<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Support\Dec;
use Brick\Math\BigDecimal;

/**
 * Итоги коммерческого предложения.
 *
 * @phpstan-type ProposalLineInput array{quantity: string|int|float|BigDecimal, unitPrice: string|int|float|BigDecimal, discountPct?: string|int|float|BigDecimal|null}
 * @phpstan-type ProposalLineTotals array{gross: BigDecimal, discount: BigDecimal, total: BigDecimal}
 * @phpstan-type ProposalTotals array{lines: list<ProposalLineTotals>, subtotal: BigDecimal, discountAmount: BigDecimal, total: BigDecimal}
 */
final class Proposal
{
    /**
     * Итоги КП (ТЗ §15): по строке gross = кол-во × цена, скидка = gross × % / 100,
     * итог = gross − скидка. Округление до 2 знаков на каждой строке.
     * discountPct — скидка на строку, % (0–100).
     *
     * @param  list<ProposalLineInput>  $lines
     * @return ProposalTotals
     */
    public static function totals(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $gross = Dec::dp(Dec::mul($l['quantity'], $l['unitPrice']), 2);
            $pct = BigDecimal::min(100, BigDecimal::max(0, Dec::of($l['discountPct'] ?? 0)));
            $discount = Dec::dp(Dec::div(Dec::mul($gross, $pct), 100), 2);
            $out[] = ['gross' => $gross, 'discount' => $discount, 'total' => Dec::sub($gross, $discount)];
        }
        $subtotal = BigDecimal::zero();
        $discountAmount = BigDecimal::zero();
        foreach ($out as $l) {
            $subtotal = Dec::add($subtotal, $l['gross']);
            $discountAmount = Dec::add($discountAmount, $l['discount']);
        }

        return [
            'lines' => $out,
            'subtotal' => $subtotal,
            'discountAmount' => $discountAmount,
            'total' => Dec::sub($subtotal, $discountAmount),
        ];
    }
}
