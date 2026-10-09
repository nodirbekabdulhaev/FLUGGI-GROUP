<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Support\Dec;
use App\Domain\Support\Js;
use Brick\Math\BigDecimal;

/**
 * Прогноз продаж и конверсия.
 *
 * @phpstan-type PipelineDeal array{amountUzs: BigDecimal|string|int|float, probability: int|float}
 */
final class Forecast
{
    /**
     * Pipeline и взвешенный прогноз (ТЗ §40): Σ amount × probability.
     *
     * @param  list<PipelineDeal>  $deals
     * @return array{pipeline: BigDecimal, weighted: BigDecimal}
     */
    public static function forecast(array $deals): array
    {
        $pipeline = BigDecimal::zero();
        $weighted = BigDecimal::zero();
        foreach ($deals as $d) {
            $amount = Dec::of($d['amountUzs']);
            $pipeline = Dec::add($pipeline, $amount);
            $p = min(100, max(0, $d['probability']));
            $weighted = Dec::add($weighted, Dec::div(Dec::mul($amount, $p), 100));
        }

        return ['pipeline' => Dec::dp($pipeline, 2), 'weighted' => Dec::dp($weighted, 2)];
    }

    /** Конверсия, % (0 при пустом знаменателе). */
    public static function conversion(int|float $converted, int|float $total): float
    {
        return $total > 0 ? Js::round(($converted / $total) * 10000) / 100 : 0.0;
    }
}
