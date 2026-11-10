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

    public const OUT_CATEGORIES = ['SALARY', 'TAX_NDFL', 'TAX_INPS', 'TAX_ITPARK', 'TAX_SOCIAL', 'TAX_TURNOVER', 'TAX_OTHER', 'SUPPLIER', 'RENT', 'UTILITIES', 'BANK_FEE', 'OTHER', 'CLIENT_PAYMENT', 'TRANSFER_OUT'];

    /** Категории, доступные при ручном вводе (переводы — отдельной формой, возвраты клиентам — из CRM). */
    public const MANUAL_IN = ['OTHER_INCOME', 'CLIENT_PAYMENT'];

    public const MANUAL_OUT = ['SALARY', 'TAX_NDFL', 'TAX_INPS', 'TAX_ITPARK', 'TAX_SOCIAL', 'TAX_TURNOVER', 'TAX_OTHER', 'SUPPLIER', 'RENT', 'UTILITIES', 'BANK_FEE', 'OTHER'];

    public const TAX_CATEGORIES = ['TAX_NDFL', 'TAX_INPS', 'TAX_ITPARK', 'TAX_SOCIAL', 'TAX_TURNOVER', 'TAX_OTHER'];

    /** Доход, с которого считается отчисление IT Park (поступления на банковские счета). */
    public const ITPARK_BASE = ['CLIENT_PAYMENT', 'OTHER_INCOME'];

    /**
     * Ставки и отчёт в налоговую. НДФЛ = ndflBaseRate % × (начислено − необлагаемая часть по льготам);
     * льгота компании (benefitCode, benefitPercent % дохода не облагается) действует для всех, у кого не заданы свои.
     * IT Park: 12 % × (100 − 37,5) % = 7,5 % от начисленного. taxReportFileName — шаблон имени файла отчёта
     * ({inn}, {code}, {year}, {month}, {mm}).
     */
    public const DEFAULTS = [
        'ndflBaseRate' => '12', 'benefitCode' => '103848', 'benefitPercent' => '37.5',
        'inpsRate' => '0.1', 'inpsWithinNdfl' => false, 'itparkRate' => '1', 'salaryPool' => true, 'taxPayLag' => 1,
        'socialRate' => '0', 'regimeFrom' => '',
        'earlierNdflRate' => '12', 'earlierInpsRate' => '0.1', 'earlierSocialRate' => '12', 'earlierTurnoverRate' => '4',
        'taxBalanceFrom' => '', 'taxOpeningNdfl' => '0', 'taxOpeningInps' => '0', 'taxOpeningItpark' => '0',
        'companyInn' => '', 'taxReportCode' => '', 'taxReportFileName' => '{inn}_{code}_46_{year}_M_{month}_11101_20_ilova.xlsx',
    ];

    private const RATE_KEYS = ['ndflBaseRate', 'benefitPercent', 'inpsRate', 'itparkRate', 'socialRate', 'earlierNdflRate', 'earlierInpsRate', 'earlierSocialRate', 'earlierTurnoverRate'];

    /** Сальдо налогов на начало учёта (taxBalanceFrom): плюс — долг, минус — переплата. */
    public const OPENING_KEYS = ['TAX_NDFL' => 'taxOpeningNdfl', 'TAX_INPS' => 'taxOpeningInps', 'TAX_ITPARK' => 'taxOpeningItpark'];

    public const PERIOD_RE = '/^\d{4}-(0[1-9]|1[0-2])$/';

    /** Все категории (для фильтров и подписей). */
    public static function categories(): array
    {
        return array_values(array_unique([...self::IN_CATEGORIES, ...self::OUT_CATEGORIES]));
    }

    /** Базовые категории ручного ввода для направления — их же можно выбрать как «учитывать как» у своей статьи. */
    public static function manual(string $direction): array
    {
        return $direction === 'IN' ? self::MANUAL_IN : self::MANUAL_OUT;
    }

    /**
     * Настройки с эффективной ставкой НДФЛ для людей с льготой компании (ndflRate, только для показа).
     *
     * @return array{ndflBaseRate:string,benefitCode:string,benefitPercent:string,ndflRate:string,inpsRate:string,inpsWithinNdfl:bool,
     *   itparkRate:string,companyInn:string,taxReportCode:string,taxReportFileName:string,taxBalanceFrom:string,
     *   taxOpeningNdfl:string,taxOpeningInps:string,taxOpeningItpark:string}
     */
    public static function settings(): array
    {
        $s = array_replace(self::DEFAULTS, array_intersect_key(Settings::get('accounting'), self::DEFAULTS));
        foreach (self::RATE_KEYS as $k) {
            $s[$k] = (string) BigDecimal::of((string) $s[$k])->stripTrailingZeros();
        }
        $s['inpsWithinNdfl'] = (bool) $s['inpsWithinNdfl'];
        $s['salaryPool'] = (bool) $s['salaryPool'];
        $s['taxPayLag'] = (int) $s['taxPayLag'] === 0 ? 0 : 1;
        foreach (self::OPENING_KEYS as $k) {
            $s[$k] = (string) self::dec($s[$k])->toScale(2);
        }
        $s['taxBalanceFrom'] = preg_match(self::PERIOD_RE, (string) $s['taxBalanceFrom']) ? (string) $s['taxBalanceFrom'] : '';
        $s['regimeFrom'] = preg_match(self::PERIOD_RE, (string) $s['regimeFrom']) ? (string) $s['regimeFrom'] : '';
        foreach (['benefitCode', 'companyInn', 'taxReportCode', 'taxReportFileName'] as $k) {
            $s[$k] = trim((string) $s[$k]);
        }
        $s['taxReportFileName'] = $s['taxReportFileName'] !== '' ? $s['taxReportFileName'] : self::DEFAULTS['taxReportFileName'];
        $exemptPct = $s['benefitCode'] !== '' ? $s['benefitPercent'] : '0';
        $s['ndflRate'] = (string) BigDecimal::of($s['ndflBaseRate'])->multipliedBy(BigDecimal::of('100')->minus($exemptPct))
            ->dividedBy(100, 3, RoundingMode::HalfUp)->stripTrailingZeros();

        $s['regime'] = 'ITPARK';
        $s['turnoverRate'] = '0';

        return $s;
    }

    /**
     * Ставки, действующие в месяце. До перехода на IT Park (regimeFrom) — прежний режим: налог с оборота
     * (earlierTurnoverRate % поступлений), НДФЛ earlierNdflRate % без льгот, ИНПС, социальный налог earlierSocialRate %
     * от начисленного; отчисления IT Park нет. С regimeFrom (или если он не задан) — текущие ставки.
     */
    public static function settingsFor(string $period): array
    {
        $s = self::settings();
        if ($s['regimeFrom'] === '' || $period >= $s['regimeFrom']) {
            return $s;
        }

        return array_replace($s, [
            'regime' => 'EARLIER', 'ndflBaseRate' => $s['earlierNdflRate'], 'ndflRate' => $s['earlierNdflRate'], 'benefitCode' => '', 'benefitPercent' => '0',
            'inpsRate' => $s['earlierInpsRate'], 'socialRate' => $s['earlierSocialRate'], 'itparkRate' => '0', 'turnoverRate' => $s['earlierTurnoverRate'],
        ]);
    }

    /** Сохранить настройки: переданные поля меняются, остальные остаются (ndflRate вычисляется и не сохраняется). */
    public static function saveSettings(array $input): array
    {
        $before = self::settings();
        $new = array_intersect_key($before, self::DEFAULTS);
        foreach (self::RATE_KEYS as $k) {
            if (array_key_exists($k, $input)) {
                $new[$k] = (string) BigDecimal::of((string) $input[$k])->stripTrailingZeros();
            }
        }
        foreach ([...array_values(self::OPENING_KEYS), 'taxBalanceFrom', 'regimeFrom'] as $k) {
            if (array_key_exists($k, $input)) {
                $new[$k] = in_array($k, ['taxBalanceFrom', 'regimeFrom'], true) ? trim((string) $input[$k]) : (string) self::dec($input[$k] ?? '0')->toScale(2);
            }
        }
        if (array_key_exists('taxPayLag', $input)) {
            $new['taxPayLag'] = (int) $input['taxPayLag'] === 0 ? 0 : 1;
        }
        if (array_key_exists('salaryPool', $input)) {
            $new['salaryPool'] = (bool) $input['salaryPool'];
        }
        if (array_key_exists('inpsWithinNdfl', $input)) {
            $new['inpsWithinNdfl'] = (bool) $input['inpsWithinNdfl'];
        }
        foreach (['benefitCode', 'companyInn', 'taxReportCode', 'taxReportFileName'] as $k) {
            if (array_key_exists($k, $input)) {
                $new[$k] = trim((string) $input[$k]);
            }
        }
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
