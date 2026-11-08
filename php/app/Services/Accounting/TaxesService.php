<?php

namespace App\Services\Accounting;

use App\Models\AccAccount;
use App\Models\AccPayroll;
use App\Models\AccPerson;
use App\Models\AccTaxFiling;
use App\Models\AccTransaction;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Налоги месяца (считаются автоматически):
 *  - НДФЛ к уплате = Σ НДФЛ ведомости − Σ ИНПС (если ИНПС удерживается в составе НДФЛ), ИНПС = Σ ИНПС;
 *  - отчисление IT Park = ставка × поступления на банковские счета за месяц (оплаты клиентов и прочие доходы, без переводов);
 *  - до перехода на IT Park (настройка regimeFrom): налог с оборота = ставка × те же поступления, социальный налог =
 *    ставка × начислено, НДФЛ по прежней ставке без льгот (Accounting::settingsFor).
 * Оплачено — расходы категории TAX_* с этим периодом (без периода — по месяцу даты).
 *
 * Месяц без ведомости (например, прошлые годы) считается по истории окладов людей (TaxReport::accrued).
 *
 * Сальдо переходит из месяца в месяц: «с прошлых месяцев» = сальдо на начало учёта (настройки, плюс — долг,
 * минус — переплата) + Σ (к уплате − оплачено) за месяцы от начала учёта до предыдущего. Начало учёта —
 * taxBalanceFrom из настроек, иначе первый месяц с ведомостью зарплаты (операции, загруженные за прошлые годы, не
 * превращаются в огромную «переплату»). Остаток = с прошлых месяцев + к уплате − оплачено.
 */
final class TaxesService
{
    public const CARRIED = ['TAX_NDFL', 'TAX_INPS', 'TAX_ITPARK', 'TAX_SOCIAL', 'TAX_TURNOVER'];

    /**
     * @return array{period:string,settings:array,taxes:array<string,array{opening:?string,due:?string,paid:string,balance:?string,base:?string,rate:?string}>,
     *   payrollRows:int,drafts:int,fromSalary:bool,bankIncome:string,missingRate:bool,totalDue:string,totalPaid:string,totalBalance:string,balanceFrom:string}
     */
    public static function forPeriod(string $period): array
    {
        $s = Accounting::settings();
        $from = self::balanceFrom($s, $period);
        $first = min($from, $period);
        $months = self::monthly($first, $period);
        $sp = Accounting::settingsFor($period);
        $cur = $months[$period];

        $opening = array_fill_keys(self::CARRIED, BigDecimal::zero());
        if ($from <= $period) {
            foreach (Accounting::OPENING_KEYS as $cat => $key) {
                $opening[$cat] = Accounting::dec($s[$key]);
            }
            foreach ($months as $m => $row) {
                if ($m >= $from && $m < $period) {
                    foreach (self::CARRIED as $cat) {
                        $opening[$cat] = $opening[$cat]->plus($row['due'][$cat])->minus($row['paid'][$cat] ?? '0');
                    }
                }
            }
        }

        // Строки месяца: налоги действующего режима, а также прежние, по которым остался долг/переплата или были оплаты
        $all = [
            'TAX_NDFL' => [$cur['gross'], $sp['ndflRate']],
            'TAX_INPS' => [$cur['gross'], $sp['inpsRate']],
            'TAX_SOCIAL' => [$cur['gross'], $sp['socialRate']],
            'TAX_TURNOVER' => [$cur['bank'], $sp['turnoverRate']],
            'TAX_ITPARK' => [$cur['bank'], $sp['itparkRate']],
            'TAX_OTHER' => [null, null],
        ];
        $active = $sp['regime'] === 'EARLIER' ? ['TAX_NDFL', 'TAX_INPS', 'TAX_SOCIAL', 'TAX_TURNOVER', 'TAX_OTHER']
            : ['TAX_NDFL', 'TAX_INPS', 'TAX_ITPARK', 'TAX_OTHER', ...(BigDecimal::of($sp['socialRate'])->isPositive() ? ['TAX_SOCIAL'] : [])];
        $taxes = [];
        foreach ($all as $cat => [$base, $rate]) {
            if (! in_array($cat, $active, true) && ($opening[$cat] ?? BigDecimal::zero())->isZero() && ($cur['paid'][$cat] ?? BigDecimal::zero())->isZero()) {
                continue;
            }
            $due = $cur['due'][$cat] ?? null;
            $p = $cur['paid'][$cat] ?? BigDecimal::zero();
            $open = $opening[$cat] ?? null;
            $taxes[$cat] = [
                'opening' => $open === null ? null : (string) $open->toScale(2),
                'due' => $due === null ? null : (string) $due->toScale(2),
                'paid' => (string) $p->toScale(2),
                'balance' => $due === null ? null : (string) $open->plus($due)->minus($p)->toScale(2),
                'base' => $base === null ? null : (string) $base->toScale(2),
                'rate' => $rate,
            ];
        }
        $totalDue = BigDecimal::zero();
        $totalPaid = BigDecimal::zero();
        $totalBalance = BigDecimal::zero();
        foreach ($taxes as $t) {
            $totalDue = $totalDue->plus($t['due'] ?? '0');
            $totalPaid = $totalPaid->plus($t['paid']);
            $totalBalance = $totalBalance->plus($t['balance'] ?? '0');
        }

        return [
            'period' => $period, 'settings' => $sp, 'taxes' => $taxes,
            'payrollRows' => $cur['rows'], 'drafts' => $cur['drafts'], 'fromSalary' => $cur['fromSalary'], 'filed' => $cur['filed'], 'filedCovered' => ! $cur['filed'] && $cur['filedDue'] !== null,
            'bankIncome' => (string) $cur['bank']->toScale(2), 'missingRate' => $cur['missingRate'],
            'totalDue' => (string) $totalDue->toScale(2), 'totalPaid' => (string) $totalPaid->toScale(2),
            'totalBalance' => (string) $totalBalance->toScale(2), 'balanceFrom' => $from,
        ];
    }

    /**
     * Сальдо НДФЛ по месяцам нарастающим итогом (для страницы «Сданные отчёты»): к уплате, оплачено, остаток, откуда сумма.
     *
     * @return array{rows:list<array>,from:string,totalDue:string,totalPaid:string,balance:string}
     */
    public static function ndflLedger(string $to): array
    {
        $s = Accounting::settings();
        $from = self::balanceFrom($s, $to);
        if ($from > $to) {
            return ['rows' => [], 'from' => $from, 'totalDue' => '0.00', 'totalPaid' => '0.00', 'balance' => '0.00'];
        }
        $months = self::monthly($from, $to);
        $fm = FiledReports::map();
        $running = Accounting::dec($s['taxOpeningNdfl']);
        $due = BigDecimal::zero();
        $paid = BigDecimal::zero();
        $rows = [];
        foreach ($months as $m => $o) {
            $d = $o['due']['TAX_NDFL'];
            $p = $o['paid']['TAX_NDFL'];
            $running = $running->plus($d)->minus($p);
            $due = $due->plus($d);
            $paid = $paid->plus($p);
            $rows[] = ['period' => $m, 'people' => $fm['filed'][$m]['people'] ?? ($o['rows'] ?: null), 'income' => (string) $o['gross']->toScale(2),
                'due' => (string) $d->toScale(2), 'paid' => (string) $p->toScale(2), 'balance' => (string) $running->toScale(2),
                'source' => $o['filed'] ? 'filed' : ($o['filedDue'] !== null ? 'covered' : ($o['rows'] ? 'payroll' : ($o['fromSalary'] ? 'salary' : 'none'))),
                'filedMonth' => $o['filed'] ? (string) $fm['filed'][$m]['ndflMonthFiled']->toScale(2) : null];
        }

        return ['rows' => $rows, 'from' => $from, 'totalDue' => (string) $due->toScale(2), 'totalPaid' => (string) $paid->toScale(2), 'balance' => (string) $running->toScale(2)];
    }

    /** Начало учёта сальдо: из настроек, иначе первый месяц с ведомостью, иначе сам месяц. */
    public static function balanceFrom(array $s, string $period): string
    {
        if ($s['taxBalanceFrom'] !== '') {
            return $s['taxBalanceFrom'];
        }

        $first = array_filter([AccPayroll::min('period'), AccTaxFiling::min('period')]);

        return (string) ($first ? min($first) : $period);
    }

    /**
     * По месяцам [$from … $to]: к уплате, оплачено, база (начислено, поступления на банк).
     *
     * @return array<string, array{due:array<string,BigDecimal>,paid:array<string,BigDecimal>,gross:BigDecimal,bank:BigDecimal,missingRate:bool,rows:int,drafts:int}>
     */
    private static function monthly(string $from, string $to): array
    {
        $zero = BigDecimal::zero();
        $out = [];
        for ($m = CarbonImmutable::createFromFormat('!Y-m', $from); $m->format('Y-m') <= $to; $m = $m->addMonthNoOverflow()) {
            $out[$m->format('Y-m')] = ['ndfl' => $zero, 'inps' => $zero, 'gross' => $zero, 'bank' => $zero, 'missingRate' => false, 'rows' => 0, 'drafts' => 0, 'fromSalary' => false,
                'paid' => array_fill_keys(Accounting::TAX_CATEGORIES, $zero)];
        }
        foreach (AccPayroll::whereBetween('period', [$from, $to])->get(['period', 'gross', 'ndfl', 'inps', 'status']) as $r) {
            $o = &$out[$r->period];
            $o['ndfl'] = $o['ndfl']->plus((string) $r->ndfl);
            $o['inps'] = $o['inps']->plus((string) $r->inps);
            $o['gross'] = $o['gross']->plus((string) $r->gross);
            $o['rows']++;
            $o['drafts'] += $r->status === 'DRAFT' ? 1 : 0;
            unset($o);
        }
        // Сданные отчёты — источник правды для НДФЛ: месяц с отчётом даёт НДФЛ и доход, месяц до последнего отчёта года покрыт им (0)
        $fm = FiledReports::map();
        foreach ($out as $m => &$o) {
            $o['filed'] = isset($fm['filed'][$m]);
            $o['filedDue'] = FiledReports::due($m, $fm);
            if ($o['filed'] && $o['rows'] === 0) {
                $o['gross'] = $fm['filed'][$m]['income'];
            }
        }
        unset($o);
        // Месяцы без ведомости и без отчёта (прошлые годы) — по истории окладов, как в отчёте в налоговую
        $noPayroll = array_keys(array_filter($out, fn ($o) => $o['rows'] === 0 && ! $o['filed'] && $o['filedDue'] === null));
        if ($noPayroll) {
            $people = AccPerson::with('salaries')->where(fn ($q) => $q->where('is_active', true)->orWhereNotNull('fired_at'))->get();
            foreach ($noPayroll as $m) {
                $s = Accounting::settingsFor($m);
                foreach ($people as $p) {
                    $g = TaxReport::accrued($p, $m);
                    if ($g === null || ! $g->isPositive()) {
                        continue;
                    }
                    $x = TaxPayrollService::amounts($g, $s, TaxReport::benefitsFor($p->benefits, $s));
                    $out[$m]['ndfl'] = $out[$m]['ndfl']->plus($x['ndfl']);
                    $out[$m]['inps'] = $out[$m]['inps']->plus($x['inps']);
                    $out[$m]['gross'] = $out[$m]['gross']->plus($x['gross']);
                    $out[$m]['fromSalary'] = true;
                }
            }
        }
        [$a] = Accounting::monthDates($from);
        [, $b] = Accounting::monthDates($to);
        foreach (self::rows(fn ($q) => self::bankIncomeWhere($q)->where('acc_transactions.date', '>=', $a)->where('acc_transactions.date', '<', $b)) as $r) {
            $k = $r->date->format('Y-m');
            $out[$k]['missingRate'] = $out[$k]['missingRate'] || $r->uzs === null;
            $out[$k]['bank'] = $out[$k]['bank']->plus($r->uzs ?? $zero);
        }
        $paid = self::rows(fn ($q) => $q->where('acc_transactions.direction', 'OUT')->whereIn('acc_transactions.category', Accounting::TAX_CATEGORIES)
            ->where(fn ($w) => $w->whereBetween('acc_transactions.period', [$from, $to])
                ->orWhere(fn ($n) => $n->whereNull('acc_transactions.period')->where('acc_transactions.date', '>=', $a)->where('acc_transactions.date', '<', $b))));
        foreach ($paid as $r) {
            $k = $r->period ?: $r->date->format('Y-m');
            if (isset($out[$k])) {
                $out[$k]['paid'][$r->category] = $out[$k]['paid'][$r->category]->plus($r->uzs ?? $zero);
            }
        }
        foreach ($out as $m => &$o) {
            $s = Accounting::settingsFor($m);
            if ($o['filed'] && $o['rows'] === 0) {
                $o['inps'] = Accounting::pct($o['gross'], $s['inpsRate']);
            }
            $o['due'] = [
                'TAX_NDFL' => $o['filedDue'] ?? ($s['inpsWithinNdfl'] ? $o['ndfl']->minus($o['inps']) : $o['ndfl']),
                'TAX_INPS' => $o['inps'],
                'TAX_ITPARK' => Accounting::pct($o['bank'], $s['itparkRate']),
                'TAX_SOCIAL' => Accounting::pct($o['gross'], $s['socialRate']),
                'TAX_TURNOVER' => Accounting::pct($o['bank'], $s['turnoverRate']),
            ];
        }
        unset($o);

        return $out;
    }

    /**
     * Поступления на банковские счета за месяц (база IT Park), UZS.
     *
     * @return array{total:BigDecimal,missingRate:bool,rows:Collection}
     */
    public static function bankIncome(string $period): array
    {
        [$a, $b] = Accounting::monthDates($period);
        $rows = self::rows(fn ($q) => self::bankIncomeWhere($q)->where('acc_transactions.date', '>=', $a)->where('acc_transactions.date', '<', $b));
        $total = BigDecimal::zero();
        $missing = false;
        foreach ($rows as $r) {
            $missing = $missing || $r->uzs === null;
            $total = $total->plus($r->uzs ?? BigDecimal::zero());
        }

        return ['total' => $total, 'missingRate' => $missing, 'rows' => $rows];
    }

    private static function bankIncomeWhere($q)
    {
        return $q->where('acc_transactions.direction', 'IN')->whereIn('acc_transactions.category', Accounting::ITPARK_BASE)->where('a.kind', 'BANK');
    }

    /** Операции с суммой в UZS (поле uzs; null — нет курса USD). */
    private static function rows(callable $where): Collection
    {
        $q = AccTransaction::query()->join('acc_accounts as a', 'a.id', '=', 'acc_transactions.account_id')
            ->leftJoin('payments as p', 'p.id', '=', 'acc_transactions.payment_id')
            ->select(['acc_transactions.*', 'a.currency', 'a.name as account_name', 'p.amount_uzs as payment_uzs'])
            ->orderBy('acc_transactions.date');
        $where($q);

        return $q->get()->each(function ($r) {
            $r->setAttribute('uzs', Accounting::toUzs((string) $r->amount, $r->currency, $r->date->toDateString(), $r->payment_uzs === null ? null : (string) $r->payment_uzs));
        });
    }

    /** Банковские счета — для кнопки «Оплатить» (первый активный UZS-счёт по умолчанию). */
    public static function payAccount(): ?AccAccount
    {
        return AccAccount::where('is_active', true)->where('kind', 'BANK')->orderByRaw("currency <> 'UZS'")->orderBy('sort')->orderBy('created_at')->first();
    }
}
