<?php

namespace App\Services\Accounting;

use App\Models\AccAccount;
use App\Models\AccPayroll;
use App\Models\AccStatementLine;
use App\Models\AccTransaction;
use App\Services\Import\RowError;
use App\Support\Audit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Бухгалтерский баланс (форма №1) и отчёт о финансовых результатах (форма №2) — строки по кодам сданных форм.
 *
 * Каждая строка — одно из:
 *  • авто — считается из данных системы в реальном времени (счета, налоги, ведомости, выплаты, выручка и расходы);
 *  • вручную / из сданной формы — значение зафиксировано (acc_statement_lines) и сильнее автоматического;
 *  • перенос — строка баланса без своего значения наследует конец прошлого года (уставный капитал, оборудование, авансы и т. п.);
 *  • итог — сумма других строк по формуле формы.
 * Остаток на начало года = конец прошлого года, поэтому, например, невыплаченная зарплата прошлого года переходит в новый год как
 * задолженность по оплате труда (код 720): конец = начало + начислено по ведомостям − выплачено за год.
 * Нераспределённая прибыль (450) — сальдирующая строка: баланс сходится всегда; расхождение с чистой прибылью формы №2 показано отдельно.
 */
final class Statements
{
    /** Строки баланса, значение которых можно задать (остальные — итоги). */
    public const B_INPUT = ['010', '011', '020', '021', '040', '050', '060', '070', '080', '090', '100', '110', '120', '150', '160', '170', '180', '190', '200',
        '220', '230', '240', '250', '260', '270', '280', '290', '300', '310', '330', '340', '350', '360', '370', '380', '410', '420', '430', '440', '450', '460', '470',
        '500', '510', '520', '530', '540', '550', '560', '570', '580', '590', '610', '620', '630', '640', '650', '660', '670', '680', '690', '700', '710', '720',
        '730', '740', '750', '760'];

    /** Строки баланса, считаемые из системы, если значение не задано. */
    public const B_AUTO = ['270', '280', '330', '340', '350', '360', '450', '680', '700', '720'];

    /** Итоги баланса: код => [слагаемые, вычитаемые]. Порядок — от нижних к верхним. */
    public const B_SUM = [
        '012' => [['010'], ['011']], '022' => [['020'], ['021']], '030' => [['040', '050', '060', '070', '080'], []],
        '130' => [['012', '022', '030', '090', '100', '110', '120'], []], '140' => [['150', '160', '170', '180'], []],
        '210' => [['220', '230', '240', '250', '260', '270', '280', '290', '300', '310'], []], '320' => [['330', '340', '350', '360'], []],
        '390' => [['140', '190', '200', '210', '320', '370', '380'], []], '400' => [['130', '390'], []],
        '490' => [['500', '510', '520', '530', '540', '550', '560', '570', '580', '590'], []],
        '600' => [['610', '620', '630', '640', '650', '660', '670', '680', '690', '700', '710', '720', '730', '740', '750', '760'], []],
        '770' => [['490', '600'], []], '480' => [['410', '420', '430', '450', '460', '470'], ['440']], '780' => [['480', '770'], []],
    ];

    /** Порядок строк баланса в форме. */
    public const B_ORDER = ['010', '011', '012', '020', '021', '022', '030', '040', '050', '060', '070', '080', '090', '100', '110', '120', '130', '140', '150', '160', '170', '180',
        '190', '200', '210', '220', '230', '240', '250', '260', '270', '280', '290', '300', '310', '320', '330', '340', '350', '360', '370', '380', '390', '400',
        '410', '420', '430', '440', '450', '460', '470', '480', '490', '500', '510', '520', '530', '540', '550', '560', '570', '580', '590', '600', '610', '620', '630',
        '640', '650', '660', '670', '680', '690', '700', '710', '720', '730', '740', '750', '760', '770', '780'];

    public const F_AUTO = ['010', '020', '050', '060', '070', '090'];

    public const F_INPUT = ['010', '020', '050', '060', '070', '080', '090', '120', '130', '140', '150', '160', '180', '190', '200', '210', '230', '250', '260'];

    public const F_SUM = [
        '030' => [['010'], ['020']], '040' => [['050', '060', '070', '080'], []], '100' => [['030', '090'], ['040']], '110' => [['120', '130', '140', '150', '160'], []],
        '170' => [['180', '190', '200', '210'], []], '220' => [['100', '110'], ['170']], '240' => [['220', '230'], []], '270' => [['240'], ['250', '260']],
    ];

    public const F_ORDER = ['010', '020', '030', '040', '050', '060', '070', '080', '090', '100', '110', '120', '130', '140', '150', '160', '170', '180', '190', '200', '210',
        '220', '230', '240', '250', '260', '270'];

    // ─────────────────────────── Данные ───────────────────────────

    /** @return array<string,AccStatementLine> код => строка, заданная вручную или из формы */
    private static function stored(int $year, string $form): array
    {
        return AccStatementLine::where('year', $year)->where('form', $form)->get()->keyBy('code')->all();
    }

    /** Первый год, по которому есть данные (ограничивает перенос назад). */
    private static function firstYear(): int
    {
        $years = array_filter([
            AccStatementLine::min('year'),
            ($d = AccTransaction::min('date')) ? (int) substr((string) $d, 0, 4) : null,
            ($p = AccPayroll::min('period')) ? (int) substr((string) $p, 0, 4) : null,
        ]);

        return $years ? (int) min($years) : (int) CarbonImmutable::now()->format('Y');
    }

    /** Дата, на которую считается баланс года: конец года, для текущего года — сегодня. */
    public static function asOf(int $year): string
    {
        $end = $year.'-12-31';
        $today = CarbonImmutable::now()->toDateString();

        return $today < $end && substr($today, 0, 4) === (string) $year ? $today : $end;
    }

    /** Остатки счетов по видам на конец дня $date, UZS: CASH, BANK, USD (банк в валюте), CARD. */
    private static function accountBalances(string $date): array
    {
        $out = ['CASH' => BigDecimal::zero(), 'BANK' => BigDecimal::zero(), 'USD' => BigDecimal::zero(), 'CARD' => BigDecimal::zero()];
        $flows = AccTransaction::query()->whereDate('date', '<=', $date)
            ->selectRaw("account_id, SUM(CASE WHEN direction = 'IN' THEN amount ELSE -amount END) AS s")->groupBy('account_id')->pluck('s', 'account_id');
        foreach (AccAccount::all() as $a) {
            $opening = $a->opening_date === null || $a->opening_date->format('Y-m-d') <= $date ? Accounting::dec($a->opening_balance) : BigDecimal::zero();
            $balance = $opening->plus(Accounting::dec($flows[$a->id] ?? '0'));
            if ($a->currency === 'USD') {
                $balance = Accounting::toUzs((string) $balance, 'USD', $date) ?? BigDecimal::zero();
                $key = $a->kind === 'BANK' ? 'USD' : $a->kind;
            } else {
                $key = $a->kind;
            }
            $out[$key] = ($out[$key] ?? BigDecimal::zero())->plus($balance);
        }

        return $out;
    }

    /** Выплаты зарплаты за период по дате платежа, UZS. */
    private static function salaryPaid(string $from, string $to): BigDecimal
    {
        $sum = BigDecimal::zero();
        $rows = AccTransaction::query()->join('acc_accounts as a', 'a.id', '=', 'acc_transactions.account_id')
            ->where('acc_transactions.direction', 'OUT')->where('acc_transactions.category', 'SALARY')->whereBetween('acc_transactions.date', [$from, $to])
            ->get(['acc_transactions.amount', 'acc_transactions.date', 'a.currency']);
        foreach ($rows as $r) {
            $sum = $sum->plus(Accounting::toUzs((string) $r->amount, $r->currency, $r->date->toDateString()) ?? BigDecimal::zero());
        }

        return $sum;
    }

    /** Автоматические значения строк баланса на дату конца года. @return array<string,BigDecimal> */
    private static function autoBalance(int $year, string $end, ?array $prev): array
    {
        $zero = BigDecimal::zero();
        $acc = self::accountBalances($end);
        $tax = TaxesService::balancesAt(substr($end, 0, 7));
        $debt = fn (array $cats) => array_reduce($cats, fn (BigDecimal $c, $k) => $c->plus(BigDecimal::max($tax[$k] ?? $zero, $zero)), $zero);
        $over = fn (array $cats) => array_reduce($cats, fn (BigDecimal $c, $k) => $c->plus(BigDecimal::max(($tax[$k] ?? $zero)->negated(), $zero)), $zero);
        $budget = ['TAX_NDFL', 'TAX_SOCIAL', 'TAX_TURNOVER', 'TAX_ITPARK'];

        // Задолженность по оплате труда: остаток на начало года + начислено на руки по ведомостям − выплачено за год
        $net = $zero;
        $rows = 0;
        foreach (AccPayroll::where('period', 'like', $year.'-%')->where('period', '<=', substr($end, 0, 7))->get(['net']) as $r) {
            $net = $net->plus((string) $r->net);
            $rows++;
        }
        $salary = $prev === null && $rows === 0 ? $zero : ($prev['720']['v'] ?? $zero)->plus($net)->minus(self::salaryPaid($year.'-01-01', $end));

        return ['270' => $over($budget), '280' => $over(['TAX_INPS']), '330' => $acc['CASH'], '340' => $acc['BANK'], '350' => $acc['USD'], '360' => $acc['CARD'],
            '680' => $debt($budget), '700' => $debt(['TAX_INPS']), '720' => $salary];
    }

    private static function sum(array $vals, array $plus, array $minus): BigDecimal
    {
        $s = BigDecimal::zero();
        foreach ($plus as $c) {
            $s = $s->plus($vals[$c]['v'] ?? 0);
        }
        foreach ($minus as $c) {
            $s = $s->minus($vals[$c]['v'] ?? 0);
        }

        return $s;
    }

    /**
     * Баланс на конец года: код => ['v' => сумма, 'auto' => значение из системы или null, 'src' => auto|manual|file|carry|sum|balance|empty, 'note' => ?string].
     *
     * @return array<string,array{v:BigDecimal,auto:?BigDecimal,src:string,note:?string}>
     */
    public static function closing(int $year): array
    {
        return self::chain($year)[$year];
    }

    /**
     * Баланс на конец каждого года от первого с данными до $year: остаток на начало года — конец прошлого.
     *
     * @return array<int,array<string,array{v:BigDecimal,auto:?BigDecimal,src:string,note:?string}>>
     */
    private static function chain(int $year): array
    {
        $out = [];
        for ($y = max(self::firstYear(), $year - 8, 2000); $y <= $year; $y++) {
            $out[$y] = self::compute($y, $out[$y - 1] ?? null);
        }
        if (! isset($out[$year])) {
            $out[$year] = self::compute($year, null);
        }

        return $out;
    }

    private static function compute(int $year, ?array $prev): array
    {
        $stored = self::stored($year, 'B');
        $auto = self::autoBalance($year, self::asOf($year), $prev);
        $vals = [];
        foreach (self::B_INPUT as $code) {
            if ($code === '450') {
                continue;
            }
            $a = $auto[$code] ?? null;
            if (isset($stored[$code])) {
                $vals[$code] = ['v' => Accounting::dec($stored[$code]->amount), 'auto' => $a, 'src' => strtolower($stored[$code]->source), 'note' => $stored[$code]->note];
            } elseif ($a !== null) {
                $vals[$code] = ['v' => $a, 'auto' => $a, 'src' => 'auto', 'note' => null];
            } elseif ($prev !== null && isset($prev[$code]) && ! $prev[$code]['v']->isZero()) {
                $vals[$code] = ['v' => $prev[$code]['v'], 'auto' => null, 'src' => 'carry', 'note' => null];
            } else {
                $vals[$code] = ['v' => BigDecimal::zero(), 'auto' => null, 'src' => 'empty', 'note' => null];
            }
        }
        $derive = function (string $code) use (&$vals) {
            [$p, $m] = self::B_SUM[$code];
            $vals[$code] = ['v' => self::sum($vals, $p, $m), 'auto' => null, 'src' => 'sum', 'note' => null];
        };
        foreach (['012', '022', '030', '130', '140', '210', '320', '390', '400', '490', '600', '770'] as $code) {
            $derive($code);
        }
        // Нераспределённая прибыль — сальдирующая строка, если не задана: актив − обязательства − прочие источники собственных средств
        $others = self::sum($vals, ['410', '420', '430', '460', '470'], ['440']);
        $balancing = $vals['400']['v']->minus($vals['770']['v'])->minus($others);
        if (isset($stored['450'])) {
            $vals['450'] = ['v' => Accounting::dec($stored['450']->amount), 'auto' => $balancing, 'src' => strtolower($stored['450']->source), 'note' => $stored['450']->note];
        } else {
            $vals['450'] = ['v' => $balancing, 'auto' => $balancing, 'src' => 'balance', 'note' => null];
        }
        $derive('480');
        $derive('780');

        return $vals;
    }

    /**
     * Финансовые результаты за год: код => ['v','auto','src','note'].
     *
     * @return array<string,array{v:BigDecimal,auto:?BigDecimal,src:string,note:?string}>
     */
    public static function results(int $year): array
    {
        $stored = self::stored($year, 'F');
        $calc = [];
        foreach (AnnualReport::results($year) as [$code, , $amount]) {
            $calc[$code] = $amount;
        }
        $vals = [];
        foreach (self::F_INPUT as $code) {
            $a = in_array($code, self::F_AUTO, true) ? ($calc[$code] ?? BigDecimal::zero()) : null;
            if (isset($stored[$code])) {
                $vals[$code] = ['v' => Accounting::dec($stored[$code]->amount), 'auto' => $a, 'src' => strtolower($stored[$code]->source), 'note' => $stored[$code]->note];
            } else {
                $vals[$code] = ['v' => $a ?? BigDecimal::zero(), 'auto' => $a, 'src' => $a !== null ? 'auto' : 'empty', 'note' => null];
            }
        }
        foreach (['030', '040', '100', '110', '170', '220', '240', '270'] as $code) {
            [$p, $m] = self::F_SUM[$code];
            $vals[$code] = ['v' => self::sum($vals, $p, $m), 'auto' => null, 'src' => 'sum', 'note' => null];
        }

        return $vals;
    }

    /**
     * Данные страницы: баланс на начало и конец года, финрезультаты за год и прошлый год, проверки.
     *
     * @return array<string,mixed>
     */
    public static function page(int $year): array
    {
        $chain = self::chain($year);
        $close = $chain[$year];
        $open = $chain[$year - 1] ?? self::compute($year - 1, null);
        $cur = self::results($year);
        $prior = self::results($year - 1);
        $profit = $cur['270']['v'];
        // Расхождение: изменение нераспределённой прибыли за год и чистая прибыль по форме №2
        $change = $close['450']['v']->minus($open['450']['v']);

        return ['year' => $year, 'asOf' => self::asOf($year), 'open' => $open, 'close' => $close, 'cur' => $cur, 'prior' => $prior,
            'checks' => ['equity' => $change->toScale(2), 'profit' => $profit->toScale(2), 'gap' => $change->minus($profit)->toScale(2)]];
    }

    // ─────────────────────────── Правки ───────────────────────────

    /** Задать значение строки вручную (null — вернуть автоматическое). */
    public static function set(int $year, string $form, string $code, ?string $amount, ?string $note): void
    {
        $allowed = $form === 'B' ? self::B_INPUT : self::F_INPUT;
        if (! in_array($code, $allowed, true)) {
            throw new RowError(t('accounting.statements.errors.line'));
        }
        DB::transaction(function () use ($year, $form, $code, $amount, $note) {
            $row = AccStatementLine::where(['year' => $year, 'form' => $form, 'code' => $code])->first();
            if ($amount === null || $amount === '') {
                if ($row) {
                    $row->delete();
                    Audit::log('accounting.statement.reset', 'acc_statement_line', $row->id, ['line' => ['old' => $form.$code.' '.(string) $row->amount, 'new' => null]]);
                }

                return;
            }
            $amount = (string) Accounting::dec($amount)->toScale(2, RoundingMode::HalfUp);
            $old = $row ? (string) $row->amount : null;
            $row = $row ?: new AccStatementLine(['year' => $year, 'form' => $form, 'code' => $code]);
            $row->fill(['amount' => $amount, 'source' => 'MANUAL', 'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null, 'source_file' => null,
                'updated_by_id' => access(false)?->id()])->save();
            Audit::log('accounting.statement.set', 'acc_statement_line', $row->id, ['line' => ['old' => $old, 'new' => $form.$code.' '.$year.' '.$amount]]);
        });
    }

    // ─────────────────────────── Загрузка сданных форм ───────────────────────────

    /** Один файл сданной формы (xltx/xlsx из кабинета): форма, год, строки в сумах (в файле — тысячи). */
    public static function parse(string $path, string $name): array
    {
        try {
            $book = IOFactory::createReader(IOFactory::identify($path))->setReadDataOnly(true)->load($path);
            $l1 = $book->getSheetByName('list01');
            $l2 = $book->getSheetByName('list02');
        } catch (Throwable) {
            throw new RowError(t('accounting.statements.errors.unreadable'));
        }
        if (! $l1 || ! $l2) {
            throw new RowError(t('accounting.statements.errors.sheets'));
        }
        $head = '';
        $year = null;
        foreach ($l1->toArray(null, false, false, false) as $r) {
            foreach ($r as $v) {
                $s = is_string($v) ? $v : '';
                $head .= ' '.$s;
                if ($year === null && is_numeric($v) && (int) $v >= 2000 && (int) $v <= 2100) {
                    $year = (int) $v;
                }
            }
        }
        $form = str_contains($head, 'баланс') ? 'B' : (str_contains($head, 'натижалар') ? 'F' : null);
        if (! $form || ! $year) {
            throw new RowError(t('accounting.statements.errors.form'));
        }
        $num = fn ($v) => is_numeric($v) ? BigDecimal::of((string) $v)->multipliedBy(1000)->toScale(2, RoundingMode::HalfUp) : null;
        $cur = [];
        $prev = [];
        foreach ($l2->toArray(null, false, false, false) as $r) {
            $code = null;
            foreach ($r as $i => $v) {
                if (is_string($v) && preg_match('/^\d{3}$/', trim($v))) {
                    $code = trim($v);
                    $cells = array_slice($r, $i + 1, 4);
                    break;
                }
            }
            if ($code === null) {
                continue;
            }
            // Пустая клетка строки формы — ноль (иначе значение прошлого года перенеслось бы само)
            $zero = BigDecimal::zero()->toScale(2);
            if ($form === 'B') {
                $prev[$code] = $num($cells[0] ?? null) ?? $zero;
                $cur[$code] = $num($cells[1] ?? null) ?? $zero;
            } else {
                // Доходы и расходы в разных столбцах: за прошлый год (доход, расход), за отчётный (доход, расход)
                $pick = fn ($inc, $exp) => $num($inc) ?? $num($exp) ?? $zero;
                $prev[$code] = $pick($cells[0] ?? null, $cells[1] ?? null);
                $cur[$code] = $pick($cells[2] ?? null, $cells[3] ?? null);
            }
        }
        if (! $cur && ! $prev) {
            throw new RowError(t('accounting.statements.errors.empty'));
        }

        return ['form' => $form, 'year' => $year, 'file' => mb_substr($name, 0, 191), 'cur' => $cur, 'prev' => $prev];
    }

    /**
     * Загрузить сданные формы: значения строк (кроме итогов) записываются как «из сданной формы» — за отчётный год и за прошлый.
     *
     * @param  list<UploadedFile>  $files
     * @return array{files:int,lines:int,errors:list<string>}
     */
    public static function import(array $files): array
    {
        $parsed = [];
        $errors = [];
        foreach ($files as $f) {
            try {
                $parsed[] = self::parse((string) $f->getRealPath(), basename($f->getClientOriginalName()));
            } catch (RowError $e) {
                $errors[] = basename($f->getClientOriginalName()).': '.$e->getMessage();
            }
        }
        $lines = 0;
        DB::transaction(function () use ($parsed, &$lines) {
            foreach ($parsed as $p) {
                $allowed = $p['form'] === 'B' ? self::B_INPUT : self::F_INPUT;
                foreach ([[$p['year'], $p['cur']], [$p['year'] - 1, $p['prev']]] as [$year, $values]) {
                    foreach ($values as $code => $amount) {
                        $code = (string) $code;   // числовые ключи массива — int
                        if (! in_array($code, $allowed, true)) {
                            continue;
                        }
                        AccStatementLine::updateOrCreate(['year' => $year, 'form' => $p['form'], 'code' => $code],
                            ['amount' => $amount, 'source' => 'FILE', 'note' => null, 'source_file' => $p['file'], 'updated_by_id' => access(false)?->id()]);
                        $lines++;
                    }
                }
                Audit::log('accounting.statement.import', 'acc_statement_line', null, ['import' => ['old' => null, 'new' => ['form' => $p['form'], 'year' => $p['year'], 'file' => $p['file']]]]);
            }
        });

        return ['files' => count($parsed), 'lines' => $lines, 'errors' => $errors];
    }
}
