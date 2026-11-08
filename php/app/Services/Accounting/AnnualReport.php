<?php

namespace App\Services\Accounting;

use App\Models\AccAccount;
use App\Models\AccPayroll;
use App\Models\AccTransaction;
use App\Services\Analytics\Sheet;
use App\Support\Audit;
use App\Support\Settings;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Годовой отчёт для налоговой по данным бухгалтерии (Excel): форма №1 «Бухгалтерский баланс»
 * на начало и конец года, форма №2 «Отчёт о финансовых результатах», налоги и зарплата по месяцам,
 * движение денег по счетам. Формы — на русском, как их сдают в налоговую.
 *
 * Учёт: только банковские счета (acc_accounts.kind = BANK, в сумах и в валюте) — наличные в кассе
 * и операции по картам в отчёт не входят: ни в остатки денег, ни в доходы и расходы, ни в оплату
 * зарплаты и налогов. Зарплата — по начислениям (ведомость для налоговой); НДФЛ, ИНПС и 1 % IT Park —
 * по начислениям месяца; выплаты зарплаты и налогов уменьшают задолженность, только если оплачены
 * с банковского счёта; остальные расходы — по факту оплаты с банковского счёта.
 * Переводы между банком и кассой/картой — не доход и не расход: их сальдо показано отдельной строкой
 * пассива («снято с банковских счетов»), поэтому баланс сходится и прирост собственных средств = прибыль года.
 * В баланс попадают только денежные средства на банковских счетах и расчёты по зарплате и налогам;
 * основные средства, запасы и дебиторскую задолженность бухгалтер добавляет сам.
 */
final class AnnualReport
{
    private const MONTHS = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

    private static function num(BigDecimal $v): float
    {
        return (float) (string) $v->toScale(2);
    }

    /** Остатки денег на банковских счетах на конец дня $date (включительно), UZS: BANK — в сумах, USD — в валюте. */
    public static function money(string $date): array
    {
        $out = ['BANK' => BigDecimal::zero(), 'USD' => BigDecimal::zero()];
        $missingRate = false;
        $flows = AccTransaction::query()->whereDate('date', '<=', $date)
            ->selectRaw("account_id, SUM(CASE WHEN direction = 'IN' THEN amount ELSE -amount END) AS s")
            ->groupBy('account_id')->pluck('s', 'account_id');
        foreach (AccAccount::where('kind', 'BANK')->get() as $a) {
            $opening = $a->opening_date === null || $a->opening_date->format('Y-m-d') <= $date ? Accounting::dec($a->opening_balance) : BigDecimal::zero();
            $balance = $opening->plus(Accounting::dec($flows[$a->id] ?? '0'));
            if ($a->currency === 'USD') {
                $uzs = Accounting::toUzs((string) $balance, 'USD', $date);
                $missingRate = $missingRate || ($uzs === null && ! $balance->isZero());
                $out['USD'] = $out['USD']->plus($uzs ?? BigDecimal::zero());
            } else {
                $out['BANK'] = $out['BANK']->plus($balance);
            }
        }

        return ['lines' => $out, 'total' => array_reduce($out, fn ($s, $v) => $s->plus($v), BigDecimal::zero()), 'missingRate' => $missingRate];
    }

    /** Расчёты на конец дня $date: задолженность по оплате труда и по налогам (начислено − выплачено с банковских счетов). */
    public static function liabilities(string $date): array
    {
        $lastPeriod = substr($date, 0, 7);
        $payroll = AccPayroll::where('period', '<=', $lastPeriod)->get(['net', 'ndfl', 'inps']);
        $s = Accounting::settings();
        $net = BigDecimal::zero();
        $ndfl = BigDecimal::zero();
        $inps = BigDecimal::zero();
        foreach ($payroll as $r) {
            $net = $net->plus((string) $r->net);
            $ndfl = $ndfl->plus((string) $r->ndfl);
            $inps = $inps->plus((string) $r->inps);
        }
        // При «ИНПС в составе НДФЛ» в бюджет по НДФЛ уходит НДФЛ − ИНПС
        $ndflDue = $s['inpsWithinNdfl'] ? $ndfl->minus($inps) : $ndfl;
        $itpark = BigDecimal::zero();
        foreach (self::periodsWithBankIncome($lastPeriod) as $period) {
            $sp = Accounting::settingsFor($period);
            $itpark = $itpark->plus(Accounting::pct(TaxesService::bankIncome($period)['total'], (string) BigDecimal::of($sp['itparkRate'])->plus($sp['turnoverRate'])));
        }
        $paid = fn (array $cats) => self::sumUzs(AccTransaction::query()->where('acc_transactions.direction', 'OUT')->whereIn('acc_transactions.category', $cats)
            ->whereDate('acc_transactions.date', '<=', $date));

        return [
            'salary' => $net->minus($paid(['SALARY'])),
            'ndfl' => $ndflDue->minus($paid(['TAX_NDFL'])),
            'inps' => $inps->minus($paid(['TAX_INPS'])),
            'itpark' => $itpark->minus($paid(['TAX_ITPARK', 'TAX_TURNOVER'])),
        ];
    }

    /** Месяцы до $lastPeriod включительно, в которых были поступления на банковские счета. */
    private static function periodsWithBankIncome(string $lastPeriod): array
    {
        [, $end] = Accounting::monthDates($lastPeriod);

        return AccTransaction::query()->join('acc_accounts as a', 'a.id', '=', 'acc_transactions.account_id')
            ->where('a.kind', 'BANK')->where('acc_transactions.direction', 'IN')->whereIn('acc_transactions.category', Accounting::ITPARK_BASE)
            ->where('acc_transactions.date', '<', $end)
            ->selectRaw("DISTINCT DATE_FORMAT(acc_transactions.date, '%Y-%m') AS p")->pluck('p')->all();
    }

    /**
     * Сальдо переводов между банковскими счетами и кассой/картой по конец дня $date, UZS:
     * снято с банка (перевод из банка в кассу/на карту) − внесено на банк (из кассы/с карты).
     */
    public static function withdrawn(string $date): BigDecimal
    {
        $q = fn (string $cat) => AccTransaction::query()->where('acc_transactions.category', $cat)->whereNotNull('acc_transactions.transfer_group')
            ->whereDate('acc_transactions.date', '<=', $date)
            ->whereExists(fn ($e) => $e->selectRaw('1')->from('acc_transactions as o')->join('acc_accounts as oa', 'oa.id', '=', 'o.account_id')
                ->whereColumn('o.transfer_group', 'acc_transactions.transfer_group')->whereColumn('o.id', '<>', 'acc_transactions.id')
                ->whereNull('o.deleted_at')->where('oa.kind', '<>', 'BANK'));

        return self::sumUzs($q('TRANSFER_OUT'))->minus(self::sumUzs($q('TRANSFER_IN')));
    }

    /** Сумма операций по банковским счетам в UZS (USD — по курсу на дату операции, оплаты CRM — по их сумме в UZS). */
    private static function sumUzs($query): BigDecimal
    {
        $rows = $query->join('acc_accounts as aa', 'aa.id', '=', 'acc_transactions.account_id')->where('aa.kind', 'BANK')
            ->leftJoin('payments as pp', 'pp.id', '=', 'acc_transactions.payment_id')
            ->get(['acc_transactions.amount', 'acc_transactions.date', 'aa.currency', 'pp.amount_uzs']);
        $sum = BigDecimal::zero();
        foreach ($rows as $r) {
            $sum = $sum->plus(Accounting::toUzs((string) $r->amount, $r->currency, $r->date->format('Y-m-d'), $r->amount_uzs === null ? null : (string) $r->amount_uzs) ?? BigDecimal::zero());
        }

        return $sum;
    }

    /** Операции года по банковским счетам по направлению и категориям, UZS. */
    private static function flow(int $year, string $direction, array $cats): BigDecimal
    {
        return self::sumUzs(AccTransaction::query()->where('acc_transactions.direction', $direction)->whereIn('acc_transactions.category', $cats)
            ->whereYear('acc_transactions.date', $year));
    }

    /**
     * Строки формы №2 за год: [код, показатель, сумма].
     *
     * @return list<array{0:string,1:string,2:BigDecimal}>
     */
    public static function results(int $year): array
    {
        $revenue = self::flow($year, 'IN', ['CLIENT_PAYMENT'])->minus(self::flow($year, 'OUT', ['CLIENT_PAYMENT']));
        $cost = self::flow($year, 'OUT', ['SUPPLIER']);
        $gross = BigDecimal::zero();
        foreach (AccPayroll::where('period', 'like', $year.'-%')->get(['gross']) as $r) {
            $gross = $gross->plus((string) $r->gross);
        }
        $admin = $gross->plus(self::flow($year, 'OUT', ['RENT', 'UTILITIES', 'OTHER']));
        $itpark = BigDecimal::zero();
        foreach (range(1, 12) as $m) {
            $per = $year.'-'.str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            $sp = Accounting::settingsFor($per);
            $itpark = $itpark->plus(Accounting::pct(TaxesService::bankIncome($per)['total'], (string) BigDecimal::of($sp['itparkRate'])->plus($sp['turnoverRate'])));
        }
        $otherOps = $itpark->plus(self::flow($year, 'OUT', ['BANK_FEE', 'TAX_OTHER']));
        $sales = BigDecimal::zero();
        $period = $sales->plus($admin)->plus($otherOps);
        $otherIncome = self::flow($year, 'IN', ['OTHER_INCOME']);
        $grossProfit = $revenue->minus($cost);
        $operating = $grossProfit->minus($period)->plus($otherIncome);
        $profitTax = BigDecimal::zero();

        return [
            ['010', 'Чистая выручка от реализации продукции (товаров, работ и услуг)', $revenue],
            ['020', 'Себестоимость реализованной продукции (товаров, работ и услуг)', $cost],
            ['030', 'Валовая прибыль (убыток) от реализации продукции (стр. 010 − 020)', $grossProfit],
            ['040', 'Расходы периода, всего (стр. 050 + 060 + 070)', $period],
            ['050', '  в том числе: расходы по реализации', $sales],
            ['060', '  административные расходы (в т. ч. фонд оплаты труда)', $admin],
            ['070', '  прочие операционные расходы (в т. ч. отчисления IT Park, банковские комиссии)', $otherOps],
            ['090', 'Прочие доходы от основной деятельности', $otherIncome],
            ['100', 'Прибыль (убыток) от основной деятельности (стр. 030 − 040 + 090)', $operating],
            ['220', 'Прибыль (убыток) до уплаты налога на прибыль', $operating],
            ['230', 'Налог на прибыль (резидент IT Park — освобождён)', $profitTax],
            ['270', 'Чистая прибыль (убыток) отчётного периода', $operating->minus($profitTax)],
        ];
    }

    /** Строки баланса: [счёт НСБУ, статья, на начало года, на конец года]. */
    public static function balance(int $year): array
    {
        $start = ($year - 1).'-12-31';
        $end = $year.'-12-31';
        [$m0, $m1] = [self::money($start), self::money($end)];
        [$l0, $l1] = [self::liabilities($start), self::liabilities($end)];
        [$w0, $w1] = [self::withdrawn($start), self::withdrawn($end)];
        $sumL = fn ($l) => $l['salary']->plus($l['ndfl'])->plus($l['inps'])->plus($l['itpark']);
        $row = fn (string $acc, string $name, BigDecimal $a, BigDecimal $b) => [$acc, $name, $a, $b];

        return [
            'rows' => [
                ['', 'АКТИВ', null, null],
                ['', 'II. Оборотные активы — денежные средства на банковских счетах', null, null],
                $row('5110', 'Денежные средства на расчётном счёте', $m0['lines']['BANK'], $m1['lines']['BANK']),
                $row('5210', 'Денежные средства в иностранной валюте на банковском счёте (в сумах по курсу на дату)', $m0['lines']['USD'], $m1['lines']['USD']),
                $row('', 'Итого денежные средства / БАЛАНС (актив)', $m0['total'], $m1['total']),
                ['', 'ПАССИВ', null, null],
                ['', 'I. Источники собственных средств', null, null],
                $row('8710', 'Нераспределённая прибыль (непокрытый убыток) и собственные средства', $m0['total']->minus($sumL($l0))->plus($w0), $m1['total']->minus($sumL($l1))->plus($w1)),
                $row('', 'Снято с банковских счетов в кассу и на карты (сальдо переводов; касса и карты в отчёт не входят)', $w0->negated(), $w1->negated()),
                ['', 'II. Обязательства (текущие)', null, null],
                $row('6710', 'Задолженность по оплате труда', $l0['salary'], $l1['salary']),
                $row('6410', 'Задолженность по платежам в бюджет — НДФЛ', $l0['ndfl'], $l1['ndfl']),
                $row('6520', 'Задолженность по ИНПС (накопительные пенсионные взносы)', $l0['inps'], $l1['inps']),
                $row('6990', 'Задолженность по отчислениям IT Park', $l0['itpark'], $l1['itpark']),
                $row('', 'Итого обязательства', $sumL($l0), $sumL($l1)),
                $row('', 'БАЛАНС (пассив)', $m0['total'], $m1['total']),
            ],
            'missingRate' => $m0['missingRate'] || $m1['missingRate'],
        ];
    }

    /** Налоги месяца, оплаченные с банковских счетов (категории TAX_* с этим периодом), UZS. */
    private static function taxesPaid(string $period): array
    {
        $out = [];
        foreach (['TAX_NDFL', 'TAX_INPS', 'TAX_ITPARK'] as $cat) {
            $out[$cat] = self::sumUzs(AccTransaction::query()->where('acc_transactions.direction', 'OUT')->where('acc_transactions.category', $cat)
                ->where('acc_transactions.period', $period));
        }

        return $out;
    }

    /** @return array{body:string,filename:string} */
    public static function build(int $year): array
    {
        $company = Settings::company();
        $b = self::balance($year);
        $money = fn ($v) => $v === null ? null : self::num($v);

        $notes = new Sheet('Пояснения', [['header' => 'Годовой отчёт по данным бухгалтерии '.brand()->name(), 'width' => 60], ['header' => '', 'width' => 70]], array_values(array_filter([
            ['Организация', $company['legalName'] ?: ($company['name'] ?: '—')],
            ['ИНН (СТИР)', $company['inn'] ?: '—'],
            ['Год', (string) $year],
            ['Валюта отчёта', 'сум (UZS); счета в USD пересчитаны по курсу на дату'],
            ['Какие счета', 'Только банковские счета (в сумах и в валюте). Наличные в кассе и операции по картам в отчёт не входят: ни в остатки денег, ни в доходы и расходы, ни в оплату зарплаты и налогов.'],
            ['Как считается', 'Деньги — по операциям банковских счетов; зарплата, НДФЛ, ИНПС и 1 % IT Park — по начислениям, их оплата учитывается, только если она прошла с банковского счёта; остальные расходы — по факту оплаты с банковского счёта. Переводы между банком и кассой/картой — не доход и не расход: их сальдо показано отдельной строкой пассива.'],
            ['Что дополнить', 'В баланс включены деньги на банковских счетах и расчёты по зарплате и налогам. Основные средства, запасы, дебиторскую и кредиторскую задолженность перед контрагентами бухгалтер добавляет в форму №1 сам.'],
            ['Проверка', 'Перед сдачей сверьте номера строк с действующими бланками форм №1 и №2.'],
            $b['missingRate'] ? ['Внимание', 'Нет курса USD на одну из дат — суммы в валюте посчитаны не полностью. Внесите курс в «Настройки → Справочники».'] : null,
        ])));

        $balance = new Sheet('Форма 1 — Баланс', [
            ['header' => 'Счёт НСБУ'], ['header' => 'Статья', 'width' => 64],
            ['header' => 'На 01.01.'.$year, 'money' => true, 'width' => 18], ['header' => 'На 31.12.'.$year, 'money' => true, 'width' => 18],
        ], array_map(fn ($r) => [$r[0], $r[1], $money($r[2]), $money($r[3])], $b['rows']));

        $results = new Sheet('Форма 2 — Фин. результаты', [
            ['header' => 'Код строки'], ['header' => 'Показатель', 'width' => 72], ['header' => 'За '.$year.' год', 'money' => true, 'width' => 20],
        ], array_map(fn ($r) => [$r[0], $r[1], self::num($r[2])], self::results($year)));

        $taxRows = [];
        $payRows = [];
        foreach (range(1, 12) as $i => $m) {
            $period = $year.'-'.str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            $t = TaxesService::forPeriod($period);
            $x = $t['taxes'];
            $paidBank = self::taxesPaid($period);
            $taxRows[] = [self::MONTHS[$i], self::num(Accounting::dec($x['TAX_NDFL']['base'])), self::num(Accounting::dec($x['TAX_NDFL']['due'])), self::num($paidBank['TAX_NDFL']),
                self::num(Accounting::dec($x['TAX_INPS']['due'])), self::num($paidBank['TAX_INPS']),
                self::num(Accounting::dec($t['bankIncome'])), self::num(Accounting::dec($x['TAX_ITPARK']['due'] ?? null)), self::num($paidBank['TAX_ITPARK'])];
            foreach (AccPayroll::with('person')->where('period', $period)->get() as $r) {
                $payRows[] = [self::MONTHS[$i], $r->person?->full_name, $r->person?->pinfl, (float) (string) $r->gross, (float) (string) $r->ndfl, (float) (string) $r->inps, (float) (string) $r->net,
                    $r->status === 'APPROVED' ? 'Утверждено' : 'Черновик'];
            }
        }
        $taxes = new Sheet('Налоги по месяцам', [
            ['header' => 'Месяц', 'width' => 12], ['header' => 'ФОТ (начислено)', 'money' => true, 'width' => 16], ['header' => 'НДФЛ к уплате', 'money' => true, 'width' => 15],
            ['header' => 'НДФЛ оплачено (банк)', 'money' => true, 'width' => 15], ['header' => 'ИНПС к уплате', 'money' => true, 'width' => 14], ['header' => 'ИНПС оплачено (банк)', 'money' => true, 'width' => 14],
            ['header' => 'Поступления на банк. счета', 'money' => true, 'width' => 18], ['header' => 'IT Park к уплате', 'money' => true, 'width' => 15], ['header' => 'IT Park оплачено (банк)', 'money' => true, 'width' => 15],
        ], $taxRows);
        $payroll = new Sheet('Зарплата за год', [
            ['header' => 'Месяц', 'width' => 12], ['header' => 'ФИО', 'width' => 28], ['header' => 'ПИНФЛ', 'width' => 16], ['header' => 'Начислено', 'money' => true, 'width' => 15],
            ['header' => 'НДФЛ', 'money' => true, 'width' => 13], ['header' => 'ИНПС', 'money' => true, 'width' => 12], ['header' => 'На руки', 'money' => true, 'width' => 15], ['header' => 'Статус', 'width' => 12],
        ], $payRows);

        $accRows = [];
        foreach (AccAccount::where('kind', 'BANK')->orderBy('sort')->orderBy('name')->get() as $a) {
            $before = AccTransaction::where('account_id', $a->id)->whereDate('date', '<', $year.'-01-01')->selectRaw("SUM(CASE WHEN direction = 'IN' THEN amount ELSE -amount END) AS s")->value('s');
            $opening = $a->opening_date === null || $a->opening_date->format('Y') < (string) $year ? Accounting::dec($a->opening_balance) : BigDecimal::zero();
            $startBal = $opening->plus(Accounting::dec($before));
            $in = Accounting::dec(AccTransaction::where('account_id', $a->id)->where('direction', 'IN')->whereYear('date', $year)->sum('amount'));
            $out = Accounting::dec(AccTransaction::where('account_id', $a->id)->where('direction', 'OUT')->whereYear('date', $year)->sum('amount'));
            $openedThisYear = $a->opening_date !== null && $a->opening_date->format('Y') === (string) $year ? Accounting::dec($a->opening_balance) : BigDecimal::zero();
            $accRows[] = [$a->name, 'Банковский счёт', $a->currency,
                self::num($startBal), self::num($openedThisYear), self::num($in), self::num($out), self::num($startBal->plus($openedThisYear)->plus($in)->minus($out))];
        }
        $accounts = new Sheet('Движение денег (банк)', [
            ['header' => 'Счёт', 'width' => 26], ['header' => 'Вид', 'width' => 16], ['header' => 'Валюта'], ['header' => 'Остаток на 01.01', 'money' => true, 'width' => 16],
            ['header' => 'Начальный остаток (открыт в году)', 'money' => true, 'width' => 18], ['header' => 'Приход', 'money' => true, 'width' => 16],
            ['header' => 'Расход', 'money' => true, 'width' => 16], ['header' => 'Остаток на 31.12', 'money' => true, 'width' => 16],
        ], $accRows);

        DB::transaction(fn () => Audit::log('export.run', 'acc_annual_report', null, ['export' => ['old' => null, 'new' => ['year' => $year]]]));

        return ['body' => Sheet::xlsx([$notes, $balance, $results, $taxes, $payroll, $accounts]), 'filename' => 'fluggi-annual-report-'.$year.'.xlsx'];
    }
}
