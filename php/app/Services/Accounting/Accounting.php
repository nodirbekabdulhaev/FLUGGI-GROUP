<?php

namespace App\Services\Accounting;

use App\Models\ExchangeRate;
use App\Support\Audit;
use App\Support\Settings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Бухгалтерия: справочники (виды счетов, категории операций), настройки ставок и денежные помощники.
 * Бухгалтер ведёт учёт отдельно от CRM; из CRM приходят только подтверждённые оплаты клиентов.
 */
final class Accounting
{
    public const KINDS = ['BANK', 'CASH', 'CARD'];

    public const CURRENCIES = ['UZS', 'USD'];

    /** Способы оплаты CRM (payments.method). */
    public const CRM_METHODS = ['CASH', 'BANK', 'CARD', 'TRANSFER', 'OTHER'];

    public const IN_CATEGORIES = ['CLIENT_PAYMENT', 'OTHER_INCOME', 'TRANSFER_IN'];

    public const OUT_CATEGORIES = ['SALARY', 'TAX_NDFL', 'TAX_INPS', 'TAX_ITPARK', 'TAX_OTHER', 'SUPPLIER', 'RENT', 'UTILITIES', 'BANK_FEE', 'OTHER', 'CLIENT_PAYMENT', 'TRANSFER_OUT'];

    /** Категории, доступные при ручном вводе (переводы — отдельной формой, возвраты клиентам — из CRM). */
    public const MANUAL_IN = ['OTHER_INCOME', 'CLIENT_PAYMENT'];

    public const MANUAL_OUT = ['SALARY', 'TAX_NDFL', 'TAX_INPS', 'TAX_ITPARK', 'TAX_OTHER', 'SUPPLIER', 'RENT', 'UTILITIES', 'BANK_FEE', 'OTHER'];

    public const TAX_CATEGORIES = ['TAX_NDFL', 'TAX_INPS', 'TAX_ITPARK', 'TAX_OTHER'];

    /** Доход, с которого считается отчисление IT Park (поступления на банковские счета). */
    public const ITPARK_BASE = ['CLIENT_PAYMENT', 'OTHER_INCOME'];

    public const DEFAULTS = ['ndflRate' => '7.5', 'inpsRate' => '0.1', 'inpsWithinNdfl' => false, 'itparkRate' => '1'];

    public const PERIOD_RE = '/^\d{4}-(0[1-9]|1[0-2])$/';

    /** Все категории (для фильтров и подписей). */
    public static function categories(): array
    {
        return array_values(array_unique([...self::IN_CATEGORIES, ...self::OUT_CATEGORIES]));
    }

    /** @return array{ndflRate:string,inpsRate:string,inpsWithinNdfl:bool,itparkRate:string} */
    public static function settings(): array
    {
        $s = array_replace(self::DEFAULTS, array_intersect_key(Settings::get('accounting'), self::DEFAULTS));
        foreach (['ndflRate', 'inpsRate', 'itparkRate'] as $k) {
            $s[$k] = (string) BigDecimal::of((string) $s[$k])->stripTrailingZeros();
        }
        $s['inpsWithinNdfl'] = (bool) $s['inpsWithinNdfl'];

        return $s;
    }

    /** @param  array{ndflRate:string,inpsRate:string,inpsWithinNdfl:bool,itparkRate:string}  $input */
    public static function saveSettings(array $input): array
    {
        $before = self::settings();
        $new = [
            'ndflRate' => (string) BigDecimal::of($input['ndflRate'])->stripTrailingZeros(),
            'inpsRate' => (string) BigDecimal::of($input['inpsRate'])->stripTrailingZeros(),
            'inpsWithinNdfl' => (bool) $input['inpsWithinNdfl'],
            'itparkRate' => (string) BigDecimal::of($input['itparkRate'])->stripTrailingZeros(),
        ];
        DB::transaction(function () use ($before, $new) {
            Settings::save('accounting', $new);
            Audit::log('accounting.settings', 'setting', 'accounting', Audit::diff($before, $new, array_keys($new)));
        });

        return self::settings();
    }

    // ─────────────────────────── Деньги ───────────────────────────

    public static function dec(mixed $v): BigDecimal
    {
        return BigDecimal::of($v === null || $v === '' ? '0' : (string) $v);
    }

    /** Округление до тийина/цента: 2 знака, half-up. */
    public static function round(BigDecimal $v): BigDecimal
    {
        return $v->toScale(2, RoundingMode::HalfUp);
    }

    /** Процент от суммы: amount × rate / 100, 2 знака. */
    public static function pct(BigDecimal|string $amount, string $rate): BigDecimal
    {
        return self::round(self::dec($amount)->multipliedBy($rate)->dividedBy(100, 8, RoundingMode::HalfUp));
    }

    /** @var array<string,?string> */
    private static array $rates = [];

    /**
     * Курс USD → UZS на дату: последний не позже даты, иначе ближайший после (ещё не внесён), иначе null.
     */
    public static function usdRate(string $date): ?string
    {
        if (! array_key_exists($date, self::$rates)) {
            $rate = ExchangeRate::where('currency', 'USD')->whereDate('date', '<=', $date)->orderByDesc('date')->value('rate_to_uzs')
                ?? ExchangeRate::where('currency', 'USD')->orderBy('date')->value('rate_to_uzs');
            self::$rates[$date] = $rate === null ? null : (string) $rate;
        }

        return self::$rates[$date];
    }

    public static function forgetRates(): void
    {
        self::$rates = [];
    }

    /** Сумма операции в UZS (для отчётов и налогов). Нет курса USD — null. */
    public static function toUzs(string $amount, string $currency, string $date, ?string $paymentUzs = null): ?BigDecimal
    {
        if ($currency === 'UZS') {
            return self::dec($amount);
        }
        if ($paymentUzs !== null) {
            return self::dec($paymentUzs);
        }
        $rate = self::usdRate($date);

        return $rate === null ? null : self::round(self::dec($amount)->multipliedBy($rate));
    }

    /** Календарные даты месяца YYYY-MM: [первый день, первый день следующего). */
    public static function monthDates(string $period): array
    {
        $a = CarbonImmutable::createFromFormat('!Y-m', $period);

        return [$a->format('Y-m-d'), $a->addMonthNoOverflow()->format('Y-m-d')];
    }

    public static function monthLabel(string $period): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $period)->format('m.Y');
    }
}
