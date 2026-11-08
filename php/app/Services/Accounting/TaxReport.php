<?php

namespace App\Services\Accounting;

use App\Models\AccPayroll;
use App\Models\AccPerson;
use App\Support\Settings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Ежемесячный отчёт в налоговую — приложение 4 к расчёту НДФЛ и социального налога: по каждому человеку
 * доход по трудовым отношениям с начала года и за месяц, НДФЛ, льготы (код, необлагаемая сумма с начала года и за месяц).
 *
 * Доход месяца — начисление из ведомости (acc_payroll), если она есть за этот месяц (бухгалтер мог поправить сумму),
 * иначе оклад, действующий в этом месяце (история окладов), пропорционально календарным дням при приёме или
 * увольнении внутри месяца — как «Рассчитать месяц» в зарплате. НДФЛ месяца из ведомости — как удержано;
 * без ведомости: ndflBaseRate % × (доход − необлагаемая часть). С начала года — сумма месяцев января…отчётного.
 */
final class TaxReport
{
    public const MAX_BENEFITS = 10;

    /** Тип лица (столбец 6), вид договора (8), рабочее место (10) — коды формы. */
    public const RESIDENT_TYPES = [1, 2];

    public const CONTRACT_TYPES = [1, 2, 3, 4, 5];

    public const WORKPLACE_TYPES = [1, 2, 3, 4, 5];

    public const TEMPLATE = 'templates/tax/ndfl-ilova-4-5.xlsx';

    /** Первая строка данных на листах шаблона. */
    public const FIRST_ROW = 15;

    // ─────────────────────────── Расчёт ───────────────────────────

    /**
     * Льготы человека: свои (benefits), иначе льгота компании из настроек; [] у человека — без льгот.
     *
     * @return list<array{code:string,percent:?string,amount:?string}>
     */
    public static function benefits(?array $own, array $s): array
    {
        if ($own === null) {
            return $s['benefitCode'] !== '' && BigDecimal::of($s['benefitPercent'])->isPositive()
                ? [['code' => $s['benefitCode'], 'percent' => $s['benefitPercent'], 'amount' => null]] : [];
        }
        $out = [];
        foreach (array_slice($own, 0, self::MAX_BENEFITS) as $b) {
            $code = trim((string) ($b['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $pct = isset($b['percent']) && $b['percent'] !== '' && $b['percent'] !== null ? (string) BigDecimal::of((string) $b['percent'])->stripTrailingZeros() : null;
            $out[] = ['code' => $code, 'percent' => $pct, 'amount' => $pct === null ? (string) Accounting::dec($b['amount'] ?? '0')->toScale(2) : null];
        }

        return $out;
    }

    /**
     * Необлагаемая часть по каждой льготе (процент от начисленного или фиксированная сумма в месяц, не больше остатка)
     * и НДФЛ с облагаемой части.
     *
     * @return array{exempt:list<BigDecimal>,exemptTotal:BigDecimal,taxable:BigDecimal,ndfl:BigDecimal}
     */
    public static function split(BigDecimal $gross, array $benefits, array $s): array
    {
        $gross = Accounting::round($gross);
        $left = $gross;
        $exempt = [];
        foreach ($benefits as $b) {
            $amt = $b['percent'] !== null ? Accounting::pct($gross, $b['percent']) : Accounting::dec($b['amount']);
            $amt = BigDecimal::max(BigDecimal::zero(), BigDecimal::min($amt, $left))->toScale(2);
            $left = $left->minus($amt);
            $exempt[] = $amt;
        }
        $total = $gross->minus($left);

        return ['exempt' => $exempt, 'exemptTotal' => $total, 'taxable' => $left, 'ndfl' => Accounting::pct($left, $s['ndflBaseRate'])];
    }

    /** Оклад, действующий в месяце: последняя запись истории не позже месяца; раньше первой записи — первая; без истории — оклад карточки. */
    public static function salaryFor(AccPerson $p, string $period): BigDecimal
    {
        $history = $p->relationLoaded('salaries') ? $p->salaries : $p->salaries()->get();
        if ($history->isEmpty()) {
            return Accounting::dec($p->monthly_salary);
        }
        $row = $history->sortBy('effective_from')->filter(fn ($h) => $h->effective_from <= $period)->last() ?? $history->sortBy('effective_from')->first();

        return Accounting::dec($row->amount);
    }

    /** Начислено за месяц по окладу: пропорционально календарным дням работы; null — в этом месяце не работал. */
    public static function accrued(AccPerson $p, string $period): ?BigDecimal
    {
        $days = TaxPayrollService::employedDays($p->hired_at?->toDateString(), $p->fired_at?->toDateString(), $period);
        if (! $days) {
            return null;
        }
        $gross = self::salaryFor($p, $period);

        return $days[0] < $days[1] ? $gross->multipliedBy($days[0])->dividedBy($days[1], 2, RoundingMode::HalfUp) : $gross->toScale(2);
    }

    /**
     * Отчёт за месяц YYYY-MM.
     *
     * @return array{period:string,year:int,month:int,rows:list<array>,totals:array,slots:int,warnings:list<string>,filename:string,drafts:int}
     */
    public static function build(string $period): array
    {
        $s = Accounting::settings();
        $start = CarbonImmutable::createFromFormat('!Y-m', $period);
        $year = (int) $start->format('Y');
        $month = (int) $start->format('n');
        $monthEnd = $start->endOfMonth()->toDateString();
        $periods = array_map(fn ($m) => sprintf('%04d-%02d', $year, $m), range(1, $month));

        $payroll = AccPayroll::whereIn('period', $periods)->get()->groupBy('person_id')->map(fn ($g) => $g->keyBy('period'));
        $people = AccPerson::with('salaries')->get()->sortBy(fn ($p) => mb_strtoupper(self::name($p)))->values();
        $zero = BigDecimal::zero();
        $rows = [];
        $slots = 0;
        foreach ($people as $p) {
            // По окладу — активные и уволенные (дата увольнения ограничивает месяцы); неактивные без даты — только по ведомости
            $bySalary = $p->is_active || $p->fired_at !== null;
            $benefits = self::benefits($p->benefits, $s);
            $sum = ['ytdIncome' => $zero, 'ytdNdfl' => $zero, 'monthIncome' => $zero, 'monthNdfl' => $zero];
            $ben = array_map(fn ($b) => ['code' => $b['code'], 'ytd' => $zero, 'month' => $zero], $benefits);
            $mismatch = [];
            $fromSalary = 0;
            foreach ($periods as $per) {
                $row = $payroll[$p->id][$per] ?? null;
                if ($row) {
                    $gross = Accounting::dec($row->gross);
                    $split = self::split($gross, $benefits, $s);
                    $ndfl = Accounting::dec($row->ndfl);
                    if ($ndfl->minus($split['ndfl'])->abs()->isGreaterThan(1)) {
                        $mismatch[] = Accounting::monthLabel($per);
                    }
                } elseif ($bySalary && ($gross = self::accrued($p, $per)) !== null) {
                    $split = self::split($gross, $benefits, $s);
                    $ndfl = $split['ndfl'];
                    $fromSalary++;
                } else {
                    continue;
                }
                $sum['ytdIncome'] = $sum['ytdIncome']->plus($gross);
                $sum['ytdNdfl'] = $sum['ytdNdfl']->plus($ndfl);
                foreach ($split['exempt'] as $i => $amt) {
                    $ben[$i]['ytd'] = $ben[$i]['ytd']->plus($amt);
                }
                if ($per === $period) {
                    $sum['monthIncome'] = $gross;
                    $sum['monthNdfl'] = $ndfl;
                    foreach ($split['exempt'] as $i => $amt) {
                        $ben[$i]['month'] = $amt;
                    }
                }
            }
            $employedNow = $bySalary && TaxPayrollService::employedDays($p->hired_at?->toDateString(), $p->fired_at?->toDateString(), $period) !== null;
            if (! $sum['ytdIncome']->isPositive() && ! $employedNow) {
                continue;
            }
            $slots = max($slots, count($ben));
            $name = mb_strtoupper(self::name($p));
            $warnings = [];
            if ($p->pinfl === null || $p->pinfl === '') {
                $warnings[] = 'pinflMissing';
            } elseif (! preg_match('/^\d{14}$/', $p->pinfl)) {
                $warnings[] = 'pinflInvalid';
            }
            if (! self::isLatin($name)) {
                $warnings[] = 'nameNotLatin';
            }
            if (trim((string) $p->position) === '') {
                $warnings[] = 'positionMissing';
            }
            $rows[] = [
                'personId' => $p->id,
                'name' => $name,
                'position' => trim((string) $p->position),
                'pinfl' => (string) $p->pinfl,
                'workplaceDate' => $p->hired_at && $p->hired_at->toDateString() >= "$year-01-01" ? $p->hired_at->format('d.m.Y') : "01.01.$year",
                'resident' => (int) ($p->resident_type ?: 1),
                'status' => $p->fired_at && $p->fired_at->toDateString() <= $monthEnd ? 2 : 1,
                'contract' => (int) ($p->contract_type ?: 1),
                'rate' => (string) BigDecimal::of((string) ($p->work_rate ?? '1'))->stripTrailingZeros(),
                'workplaceType' => (int) ($p->workplace_type ?: 1),
                'ytdIncome' => (string) $sum['ytdIncome']->toScale(2),
                'monthIncome' => (string) $sum['monthIncome']->toScale(2),
                'ytdNdfl' => (string) $sum['ytdNdfl']->toScale(2),
                'monthNdfl' => (string) $sum['monthNdfl']->toScale(2),
                'benefits' => array_map(fn ($b) => ['code' => $b['code'], 'ytd' => (string) $b['ytd']->toScale(2), 'month' => (string) $b['month']->toScale(2)], $ben),
                'fromSalary' => $fromSalary,
                'mismatch' => $mismatch,
                'warnings' => $warnings,
            ];
        }

        $totals = ['ytdIncome' => $zero, 'monthIncome' => $zero, 'ytdNdfl' => $zero, 'monthNdfl' => $zero, 'ytdExempt' => $zero, 'monthExempt' => $zero, 'benefits' => array_fill(0, $slots, ['ytd' => $zero, 'month' => $zero])];
        foreach ($rows as $r) {
            foreach (['ytdIncome', 'monthIncome', 'ytdNdfl', 'monthNdfl'] as $k) {
                $totals[$k] = $totals[$k]->plus($r[$k]);
            }
            foreach ($r['benefits'] as $i => $b) {
                $totals['ytdExempt'] = $totals['ytdExempt']->plus($b['ytd']);
                $totals['monthExempt'] = $totals['monthExempt']->plus($b['month']);
                $totals['benefits'][$i] = ['ytd' => $totals['benefits'][$i]['ytd']->plus($b['ytd']), 'month' => $totals['benefits'][$i]['month']->plus($b['month'])];
            }
        }
        $fmt = fn (BigDecimal $v) => (string) $v->toScale(2);
        $totals = array_map(fn ($v) => is_array($v) ? array_map(fn ($b) => array_map($fmt, $b), $v) : $fmt($v), $totals);

        $warnings = [];
        if (self::inn($s) === '') {
            $warnings[] = 'innMissing';
        }
        if ($s['taxReportCode'] === '' && str_contains($s['taxReportFileName'], '{code}')) {
            $warnings[] = 'codeMissing';
        }
        $drafts = AccPayroll::where('period', $period)->where('status', 'DRAFT')->count();

        return ['period' => $period, 'year' => $year, 'month' => $month, 'rows' => $rows, 'totals' => $totals, 'slots' => $slots,
            'warnings' => $warnings, 'drafts' => $drafts, 'filename' => self::filename($period, $s)];
    }

    /** ФИО для отчёта: как в паспорте (латиницей), иначе ФИО карточки. */
    public static function name(AccPerson $p): string
    {
        $n = trim((string) ($p->passport_name ?: $p->full_name));

        return preg_replace('/\s+/u', ' ', $n);
    }

    /** Латиница (узбекская: Oʻ, Gʻ, апострофы, дефис). */
    public static function isLatin(string $name): bool
    {
        return (bool) preg_match("/^[A-Za-z][A-Za-z\\s'ʻʼ‘’`.\\-]*$/u", $name);
    }

    /** ИНН для имени файла: из настроек бухгалтерии, иначе из реквизитов компании. */
    public static function inn(array $s): string
    {
        return preg_replace('/\D/', '', $s['companyInn'] !== '' ? $s['companyInn'] : (string) (Settings::company()['inn'] ?? ''));
    }

    /** Имя файла по шаблону из настроек: {inn}, {code}, {year}, {month} (без нуля), {mm} (с нулём). */
    public static function filename(string $period, array $s): string
    {
        [$y, $m] = explode('-', $period);
        $name = strtr($s['taxReportFileName'], ['{inn}' => self::inn($s), '{code}' => $s['taxReportCode'], '{year}' => $y, '{month}' => (string) (int) $m, '{mm}' => $m]);
        $name = preg_replace('/[^A-Za-z0-9_.\-]/', '', $name);
        $name = preg_replace('/\.xlsx$/i', '', $name);

        return ($name !== '' ? $name : 'ndfl_ilova_'.$y.'_'.(int) $m).'.xlsx';
    }
}
