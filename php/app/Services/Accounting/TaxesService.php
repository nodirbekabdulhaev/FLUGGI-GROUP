<?php

namespace App\Services\Accounting;

use App\Models\AccAccount;
use App\Models\AccPayroll;
use App\Models\AccPerson;
use App\Models\AccTaxAct;
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
        $anchor = self::anchor($s, $period);
        if ($anchor) {
            $from = $anchor['from'];
        }
        $first = min($from, $period);
        $sp = Accounting::settingsFor($period);
        $opening = array_fill_keys(self::CARRIED, BigDecimal::zero());
        if ($from <= $period) {
            $walk = self::walk($from, $period, $s);
            $lastRow = end($walk);
            $cur = $lastRow['o'];
            $opening = $lastRow['opening'];
        } else {
            $cur = self::monthly($first, $period)[$period];
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
            'totalBalance' => (string) $totalBalance->toScale(2), 'balanceFrom' => $from, 'anchored' => $anchor !== null, 'firstFiled' => array_key_first($fm = FiledReports::map()['filed']),
        ];
    }

    /**
     * Сальдо на начало года из акта сверки налоговой: остаток на 31 декабря прошлого года (долг минус переплата) по налогам акта.
     * Действует, если загружен годовой акт за прошлый год и начало учёта вручную не задано.
     *
     * @return array{from:string,year:int,opening:array<string,BigDecimal>}|null
     */
    public static function anchor(array $s, string $period): ?array
    {
        if ($s['taxBalanceFrom'] !== '') {
            return null;
        }
        $year = (int) substr($period, 0, 4);
        $acts = AccTaxAct::where('year', $year - 1)->where('year_end', true)->get()->keyBy('tax_code');
        if ($acts->isEmpty()) {
            return null;
        }
        $opening = array_fill_keys(self::CARRIED, BigDecimal::zero());
        foreach (FiledReports::ACT_CODES as $code => $cat) {
            if ($a = $acts->get((string) $code)) {
                $opening[$cat] = Accounting::dec($a->closing_debt)->minus($a->closing_overpay);
            }
        }

        return ['from' => $year.'-01', 'year' => $year - 1, 'opening' => $opening];
    }

    /**
     * Месяцы с нарастающим сальдо по налогам: на январь года с актом сверки за прошлый год сальдо берётся из акта, дальше — к уплате минус оплачено.
     *
     * @return list<array{period:string,o:array,opening:array<string,BigDecimal>,balance:array<string,BigDecimal>,reset:bool}>
     */
    private static function walk(string $from, string $to, array $s): array
    {
        $months = self::monthly($from, $to);
        $run = array_fill_keys(self::CARRIED, BigDecimal::zero());
        foreach (Accounting::OPENING_KEYS as $cat => $key) {
            $run[$cat] = Accounting::dec($s[$key]);
        }
        $rows = [];
        foreach ($months as $m => $o) {
            $reset = false;
            if (substr($m, 5) === '01' && ($a = self::anchor($s, $m))) {
                $run = $a['opening'];
                $reset = true;
            }
            $opening = $run;
            foreach (self::CARRIED as $cat) {
                $run[$cat] = $run[$cat]->plus($o['due'][$cat])->minus($o['paid'][$cat]);
            }
            $rows[] = ['period' => $m, 'o' => $o, 'opening' => $opening, 'balance' => $run, 'reset' => $reset];
        }

        return $rows;
    }

    /**
     * Сальдо НДФЛ по месяцам нарастающим итогом (для страницы «Сданные отчёты»): к уплате, оплачено, остаток, откуда сумма.
     * В январе года с актом сверки за прошлый год остаток берётся из акта (reset).
     *
     * @return array{rows:list<array>,from:string,totalDue:string,totalPaid:string,balance:string}
     */
    public static function ndflLedger(string $to): array
    {
        $s = Accounting::settings();
        $from = self::balanceFrom($s, $to);
        if ($from > $to) {
            return ['rows' => [], 'from' => $from, 'firstFiled' => array_key_first(FiledReports::map()['filed']), 'totalDue' => '0.00', 'totalPaid' => '0.00', 'balance' => '0.00'];
        }
        $fm = FiledReports::map();
        $due = BigDecimal::zero();
        $paid = BigDecimal::zero();
        $rows = [];
        $balance = BigDecimal::zero();
        foreach (self::walk($from, $to, $s) as $w) {
            $o = $w['o'];
            $d = $o['due']['TAX_NDFL'];
            $p = $o['paid']['TAX_NDFL'];
            $due = $due->plus($d);
            $paid = $paid->plus($p);
            $balance = $w['balance']['TAX_NDFL'];
            $m = $w['period'];
            $rows[] = ['period' => $m, 'people' => $fm['filed'][$m]['people'] ?? ($o['rows'] ?: null), 'income' => (string) $o['gross']->toScale(2),
                'due' => (string) $d->toScale(2), 'paid' => (string) $p->toScale(2), 'balance' => (string) $balance->toScale(2), 'reset' => $w['reset'],
                'opening' => (string) $w['opening']['TAX_NDFL']->toScale(2),
                'source' => $o['filed'] ? 'filed' : ($o['filedDue'] !== null ? 'covered' : ($o['rows'] ? 'payroll' : ($o['fromSalary'] ? 'salary' : 'none'))),
                'filedMonth' => $o['filed'] ? (string) $fm['filed'][$m]['ndflMonthFiled']->toScale(2) : null];
        }

        return ['rows' => $rows, 'from' => $from, 'firstFiled' => array_key_first($fm['filed']), 'totalDue' => (string) $due->toScale(2), 'totalPaid' => (string) $paid->toScale(2), 'balance' => (string) $balance->toScale(2)];
    }

    /**
     * Сверка с личным кабинетом налогоплательщика по годам: начислено и оплачено по всем налогам, долг и переплата на конец года
     * (как «Ҳисобланган / Тўланган / Қарздорлик / Ортиқча тўлов»). Остаток считается по каждому налогу отдельно.
     *
     * @return array{from:string,years:array<string,array<string,string>>,total:array<string,string>}
     */
    public static function yearly(string $to): array
    {
        $s = Accounting::settings();
        $from = self::balanceFrom($s, $to);
        $zero = BigDecimal::zero();
        if ($from > $to) {
            return ['from' => $from, 'years' => [], 'total' => []];
        }
        $years = [];
        $walk = self::walk($from, $to, $s);
        $count = count($walk);
        foreach ($walk as $i => $w) {
            $o = $w['o'];
            $y = substr($w['period'], 0, 4);
            $years[$y] ??= ['due' => $zero, 'paid' => $zero, 'ndflDue' => $zero, 'ndflPaid' => $zero, 'inpsDue' => $zero, 'inpsPaid' => $zero, 'itparkDue' => $zero, 'itparkPaid' => $zero,
                'otherDue' => $zero, 'otherPaid' => $zero, 'debt' => $zero, 'over' => $zero];
            foreach (self::CARRIED as $cat) {
                $years[$y]['due'] = $years[$y]['due']->plus($o['due'][$cat]);
                $years[$y]['paid'] = $years[$y]['paid']->plus($o['paid'][$cat]);
            }
            $years[$y]['paid'] = $years[$y]['paid']->plus($o['paid']['TAX_OTHER']);
            $years[$y]['otherPaid'] = $years[$y]['otherPaid']->plus($o['paid']['TAX_OTHER']);
            $years[$y]['ndflDue'] = $years[$y]['ndflDue']->plus($o['due']['TAX_NDFL']);
            $years[$y]['ndflPaid'] = $years[$y]['ndflPaid']->plus($o['paid']['TAX_NDFL']);
            $years[$y]['inpsDue'] = $years[$y]['inpsDue']->plus($o['due']['TAX_INPS']);
            $years[$y]['inpsPaid'] = $years[$y]['inpsPaid']->plus($o['paid']['TAX_INPS']);
            $years[$y]['itparkDue'] = $years[$y]['itparkDue']->plus($o['due']['TAX_ITPARK'])->plus($o['due']['TAX_SOCIAL'])->plus($o['due']['TAX_TURNOVER']);
            $years[$y]['itparkPaid'] = $years[$y]['itparkPaid']->plus($o['paid']['TAX_ITPARK'])->plus($o['paid']['TAX_SOCIAL'])->plus($o['paid']['TAX_TURNOVER']);
            $next = $walk[$i + 1]['period'] ?? null;
            if ($i === $count - 1 || substr((string) $next, 0, 4) !== $y) {
                foreach ($w['balance'] as $b) {
                    $years[$y]['debt'] = $years[$y]['debt']->plus(BigDecimal::max($b, $zero));
                    $years[$y]['over'] = $years[$y]['over']->plus(BigDecimal::max($b->negated(), $zero));
                }
            }
        }
        $total = ['due' => $zero, 'paid' => $zero];
        foreach ($years as $y) {
            $total['due'] = $total['due']->plus($y['due']);
            $total['paid'] = $total['paid']->plus($y['paid']);
        }
        $fmt = fn (array $row) => array_map(fn (BigDecimal $v) => (string) $v->toScale(2), $row);

        return ['from' => $from, 'years' => array_map($fmt, $years), 'total' => $fmt($total)];
    }

    /**
     * Сравнение с актами сверки налоговой по годам и налогам: начислено, оплачено и сальдо на конец периода акта — в акте и в системе.
     * Сальдо системы = сальдо на начало года (из акта прошлого года, иначе по цепочке) + начислено − оплачено.
     *
     * @return list<array<string,mixed>>
     */
    public static function actCompare(string $to): array
    {
        $acts = AccTaxAct::orderBy('year')->get();
        if ($acts->isEmpty()) {
            return [];
        }
        $s = Accounting::settings();
        $firstYear = (int) $acts->min('year');
        $from = min($firstYear.'-01', self::balanceFrom($s, $to));
        $zero = BigDecimal::zero();
        $sums = [];
        $end = [];
        foreach (self::walk($from, $to, $s) as $w) {
            $y = (int) substr($w['period'], 0, 4);
            foreach (self::CARRIED as $cat) {
                $sums[$y][$cat]['due'] = ($sums[$y][$cat]['due'] ?? $zero)->plus($w['o']['due'][$cat]);
                $sums[$y][$cat]['paid'] = ($sums[$y][$cat]['paid'] ?? $zero)->plus($w['o']['paid'][$cat]);
                $end[$y][$cat] = $w['balance'][$cat];
            }
        }
        $rows = [];
        foreach ($acts as $a) {
            $cat = FiledReports::ACT_CODES[$a->tax_code] ?? null;
            if (! $cat || ! isset($sums[$a->year])) {
                continue;
            }
            $ourDue = $sums[$a->year][$cat]['due'];
            $ourPaid = $sums[$a->year][$cat]['paid'];
            $actClosing = Accounting::dec($a->closing_debt)->minus($a->closing_overpay);
            $rows[] = ['year' => $a->year, 'code' => $a->tax_code, 'cat' => $cat, 'name' => $a->tax_name, 'asOf' => $a->as_of->toDateString(), 'yearEnd' => (bool) $a->year_end,
                'actDue' => (string) Accounting::dec($a->accrued)->toScale(2), 'ourDue' => (string) $ourDue->toScale(2), 'diffDue' => (string) $ourDue->minus($a->accrued)->toScale(2),
                'actPaid' => (string) Accounting::dec($a->paid)->toScale(2), 'ourPaid' => (string) $ourPaid->toScale(2), 'diffPaid' => (string) $ourPaid->minus($a->paid)->toScale(2),
                'actBalance' => (string) $actClosing->toScale(2), 'ourBalance' => (string) ($end[$a->year][$cat] ?? $zero)->toScale(2)];
        }

        return $rows;
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
                // По сданному отчёту НДФЛ к уплате — за вычетом ИНПС (так начисляет налоговая: НДФЛ 12 % минус 0,1 % ИНПС)
                'TAX_NDFL' => $o['filedDue'] !== null ? ($o['filed'] ? $o['filedDue']->minus($o['inps']) : $o['filedDue'])
                    : ($s['inpsWithinNdfl'] ? $o['ndfl']->minus($o['inps']) : $o['ndfl']),
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
