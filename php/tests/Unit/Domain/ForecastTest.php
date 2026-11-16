<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Forecast;

final class ForecastTest extends DomainTestCase
{
    public function test_spec_example_100m_times_50_percent(): void
    {
        $r = Forecast::forecast([['amountUzs' => 100_000_000, 'probability' => 50]]);
        self::assertSame(50_000_000.0, $r['weighted']->toFloat());
        self::assertSame(100_000_000.0, $r['pipeline']->toFloat());
    }

    public function test_conversion(): void
    {
        self::assertSame(13.39, Forecast::conversion(17, 127));
        self::assertSame(0.0, Forecast::conversion(1, 0));
        self::assertSame([33.33, 66.67, 12.5, 100.0, 0.1], [
            Forecast::conversion(1, 3), Forecast::conversion(2, 3), Forecast::conversion(1, 8),
            Forecast::conversion(5, 5), Forecast::conversion(1, 1000),
        ]);
    }

    public function test_empty_pipeline(): void
    {
        $r = Forecast::forecast([]);
        self::assertDec('0', $r['pipeline']);
        self::assertDec('0', $r['weighted']);
    }

    public function test_probability_clamped_and_rounded_once(): void
    {
        $r = Forecast::forecast([
            ['amountUzs' => '1000.005', 'probability' => 33],
            ['amountUzs' => 500, 'probability' => 150],
            ['amountUzs' => '700', 'probability' => -10],
        ]);
        self::assertDec('2200.01', $r['pipeline']);
        self::assertDec('830', $r['weighted']);

        // Округление только итога: 3 × 0.005 = 0.015 → 0.02.
        $r = Forecast::forecast(array_fill(0, 3, ['amountUzs' => '0.01', 'probability' => 50]));
        self::assertDec('0.03', $r['pipeline']);
        self::assertDec('0.02', $r['weighted']);

        self::assertDec('33.3', Forecast::forecast([['amountUzs' => 100, 'probability' => 33.3]])['weighted']);
    }
}
