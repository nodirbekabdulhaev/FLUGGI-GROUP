<?php

namespace App\Services\References;

use App\Domain\Money;
use App\Exceptions\BusinessRule;
use App\Models\ExchangeRate;
use App\Support\Format;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Курсы валют к UZS: суммы фиксируют курс в момент сохранения. */
final class ExchangeRates
{
    /** Действующий курс на дату (последний установленный не позже даты). */
    public static function rateFor(string $currency, DateTimeInterface|string|null $at = null): string
    {
        if ($currency === 'UZS') {
            return '1';
        }
        $date = $at ? CarbonImmutable::parse($at)->toDateString() : Format::today()->toDateString();
        $rate = ExchangeRate::where('currency', $currency)->whereDate('date', '<=', $date)->orderByDesc('date')->value('rate_to_uzs');
        if ($rate === null) {
            throw new BusinessRule(t('references.errors.noRate', ['currency' => $currency]), ['currency' => t('references.errors.noRateField', ['currency' => $currency])]);
        }

        return (string) $rate;
    }

    /** @return array{rate:string, amountUzs:string} */
    public static function convert(string $amount, string $currency): array
    {
        $rate = self::rateFor($currency);

        return ['rate' => $rate, 'amountUzs' => (string) Money::toUzs($amount, $currency, $rate)->toScale(2)];
    }
}
