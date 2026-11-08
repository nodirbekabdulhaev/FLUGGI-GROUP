<?php

namespace App\Services\Accounting;

use App\Models\AccPerson;
use App\Models\AccPersonSalary;
use App\Models\AccTaxAct;
use App\Models\AccTaxFiling;
use App\Services\Import\ImportStore;
use App\Services\Import\RowError;
use App\Support\Audit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;
use ZipArchive;

/**
 * Сданные в налоговую отчёты (приложение 4: доход и НДФЛ по людям нарастающим итогом за год) — источник правды
 * для НДФЛ и сальдо за прошлые годы.
 *
 * Файл: 309026807_220084820_46_2026_M_1_11101_19_ilova.xltx — год и месяц берутся из имени. Формы менялись по годам
 * (2021 без даты рабочего места и типа лица, 2022 с датой, 2023+ с типом лица, 2026 с несколькими льготами), поэтому
 * столбцы ищутся по заголовкам, а не по номерам.
 *
 * НДФЛ месяца = Σ «НДФЛ с начала года» этого отчёта − то же в предыдущем месяце года (в январе — само значение):
 * так учитываются и пересчёты задним числом. Месяцы года до последнего сданного без своего отчёта покрыты следующим
 * (их НДФЛ 0), месяцы после последнего сданного считаются как обычно — по ведомости или окладам.
 *
 * Загрузка создаёт или дополняет людей по ПИНФЛ: принят с первого месяца с доходом, уволен, если в последнем отчёте
 * статус «ушёл» (или человека в последнем отчёте нет) — на конец последнего месяца с доходом; оклад — по истории доходов.
 */
final class FiledReports
{
    public const ENTITY = 'filed-reports';

    public const EXTENSIONS = ['xltx', 'xlsx', 'xls', 'zip'];

    public const MAX_FILES = 150;

    public const MAX_BYTES = 5 * 1024 * 1024;

    private const FILE_RE = '/_46_(\d{4})_M_(\d{1,2})_/';

    // ─────────────────────────── Чтение файлов ───────────────────────────

    /**
     * Файлы и архивы → отчёты по месяцам. Повторный месяц заменяет предыдущий (последний файл побеждает).
     *
     * @param  list<UploadedFile>  $uploads
     * @return array{months:array<string,array>,errors:list<string>}
     */
    public static function read(array $uploads): array
    {
        @set_time_limit(300);
        $months = [];
        $acts = [];
        $errors = [];
        $n = 0;
        $take = function (string $path, string $name) use (&$months, &$acts, &$errors, &$n) {
            if (++$n > self::MAX_FILES) {
                throw new RowError(t('accounting.filed.errors.tooMany', ['max' => self::MAX_FILES]));
            }
            try {
                if (mb_strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'xls') {
                    $a = self::parseAct($path, $name);
                    $acts[$a['year']] = $a;
                } else {
                    $r = self::parse($path, $name);
                    $months[$r['period']] = $r;
                }
            } catch (RowError $e) {
                $errors[] = $name.': '.$e->getMessage();
            }
        };
        foreach ($uploads as $file) {
            $name = mb_substr(basename($file->getClientOriginalName()), 0, 191);
            if (mb_strtolower($file->getClientOriginalExtension()) !== 'zip') {
                $take((string) $file->getRealPath(), $name);

                continue;
            }
            if (! class_exists(ZipArchive::class)) {
                throw new RowError(t('accounting.filed.errors.noZip'));
            }
            $zip = new ZipArchive;
            if ($zip->open((string) $file->getRealPath()) !== true) {
                $errors[] = $name.': '.t('accounting.filed.errors.badZip');

                continue;
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $entry = (string) ($stat['name'] ?? '');
                $ext = mb_strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (str_ends_with($entry, '/') || str_contains($entry, '__MACOSX') || ! in_array($ext, ['xltx', 'xlsx', 'xls'], true)) {
                    continue;
                }
                if (($stat['size'] ?? 0) > self::MAX_BYTES) {
                    $errors[] = basename($entry).': '.t('import.errors.fileSize', ['mb' => 5]);

                    continue;
                }
                $tmp = tempnam(sys_get_temp_dir(), 'flr');
                file_put_contents($tmp, (string) $zip->getFromIndex($i));
                try {
                    $take($tmp, mb_substr(basename($entry), 0, 191));
                } finally {
                    @unlink($tmp);
                }
            }
            $zip->close();
        }
        ksort($months);
        ksort($acts);

        return ['months' => $months, 'acts' => $acts, 'errors' => $errors];
    }

    /** Коды налогов в акте сверки → статьи бухгалтерии. Остальные коды (пеня 191/199 и т. п.) только показываются. */
    public const ACT_CODES = ['46' => 'TAX_NDFL', '101' => 'TAX_INPS', '36' => 'TAX_SOCIAL', '100' => 'TAX_TURNOVER'];

    /**
     * Акт сверки с налоговой («Солиштирма далолатнома», xls из личного кабинета): год из заголовка, по каждому налогу сальдо на начало
     * и конец (текущий и продлённый долг, пеня, переплата), начислено и оплачено. Акт за прошлый год — сальдо на 31 декабря,
     * за текущий — на дату формирования.
     */
    public static function parseAct(string $path, string $name): array
    {
        try {
            $ws = IOFactory::load($path)->getSheet(0);
            $grid = $ws->toArray(null, true, false, false);
        } catch (Throwable) {
            throw new RowError(t('accounting.filed.errors.unreadable'));
        }
        $title = (string) ($grid[0][0] ?? '');
        $stamp = (string) ($grid[1][0] ?? '');
        if (! preg_match('/(\d{4})\s*йил учун/u', $title, $y) || ! preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $stamp, $g)) {
            throw new RowError(t('accounting.filed.errors.act'));
        }
        $year = (int) $y[1];
        $asOf = CarbonImmutable::createFromFormat('!d.m.Y', $g[1].'.'.$g[2].'.'.$g[3]);
        $num = fn ($v) => is_numeric($v) ? (string) BigDecimal::of((string) $v)->toScale(2, RoundingMode::HalfUp) : '0.00';
        $rows = [];
        foreach ($grid as $i => $r) {
            if ($i < 5 || ! isset($r[1]) || ! preg_match('/^\d{1,4}$/', trim((string) $r[1]))) {
                continue;
            }
            $v = array_map($num, array_slice($r, 3, 20));
            $rows[trim((string) $r[1])] = ['code' => trim((string) $r[1]), 'name' => mb_substr(trim((string) ($r[2] ?? '')), 0, 191),
                'openingDebt' => (string) BigDecimal::of($v[0])->plus($v[1])->toScale(2), 'openingOverpay' => $v[4],
                'closingDebt' => (string) BigDecimal::of($v[5])->plus($v[6])->toScale(2), 'closingPenalty' => (string) BigDecimal::of($v[7])->plus($v[8])->toScale(2), 'closingOverpay' => $v[9],
                'accrued' => $v[14], 'accruedPenalty' => $v[15], 'paid' => $v[19], 'refunded' => $v[17]];
        }
        if (! $rows) {
            throw new RowError(t('accounting.filed.errors.act'));
        }
        $yearEnd = $asOf->format('Y') > (string) $year || ($asOf->format('Y') === (string) $year && $asOf->format('m-d') === '12-31');

        return ['year' => $year, 'file' => $name, 'asOf' => $yearEnd ? $year.'-12-31' : $asOf->toDateString(), 'yearEnd' => $yearEnd, 'rows' => $rows];
    }

    /** Один файл отчёта → месяц и строки людей. */
    public static function parse(string $path, string $name): array
    {
        if (! preg_match(self::FILE_RE, $name, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
            throw new RowError(t('accounting.filed.errors.name'));
        }
        $period = sprintf('%04d-%02d', (int) $m[1], (int) $m[2]);
        try {
            $reader = IOFactory::createReaderForFile($path);
            if (method_exists($reader, 'setReadDataOnly')) {
                $reader->setReadDataOnly(true);
            }
            $book = $reader->load($path);
            $ws = $book->getSheetByName('list01') ?? $book->getSheet(0);
        } catch (Throwable) {
            throw new RowError(t('accounting.filed.errors.unreadable'));
        }
        $col = self::columns($ws);
        foreach (['name', 'ytd', 'inc', 'tytd', 'tmon'] as $need) {
            if (! isset($col[$need])) {
                throw new RowError(t('accounting.filed.errors.columns'));
            }
        }
        $get = fn (string $k, int $r) => isset($col[$k]) ? $ws->getCell(Coordinate::stringFromColumnIndex($col[$k]).$r)->getValue() : null;
        $rows = [];
        $empty = 0;
        for ($r = 14; $r <= 600 && $empty < 8; $r++) {
            $nameCell = trim((string) self::plain($get('name', $r)));
            if ($nameCell === '') {
                $empty++;

                continue;
            }
            $empty = 0;
            $benefits = [];
            foreach ($col['ben'] ?? [] as $bc) {
                $code = trim((string) $ws->getCell(Coordinate::stringFromColumnIndex($bc).$r)->getValue());
                if ($code !== '' && $code !== '0') {
                    $benefits[] = ['code' => $code, 'ytd' => self::money($ws->getCell(Coordinate::stringFromColumnIndex($bc + 1).$r)->getValue()),
                        'month' => self::money($ws->getCell(Coordinate::stringFromColumnIndex($bc + 2).$r)->getValue())];
                }
            }
            $rows[] = [
                'n' => count($rows) + 1,
                'name' => self::name($nameCell),
                'position' => mb_substr(trim((string) self::plain($get('pos', $r))), 0, 191),
                'pinfl' => preg_replace('/\D+/', '', (string) self::plain($get('pinfl', $r))),
                'status' => (int) self::plain($get('status', $r)) === 2 ? 2 : 1,
                'workStart' => self::date($get('date', $r)),
                'resident' => (int) self::plain($get('res', $r)) ?: 1,
                'contract' => (int) self::plain($get('contract', $r)) ?: 1,
                'rate' => (string) (self::plain($get('rate', $r)) ?: '1'),
                'workplace' => min(5, max(1, (int) self::plain($get('wp', $r)) ?: 1)),
                'incomeYtd' => self::money($get('ytd', $r)), 'incomeMonth' => self::money($get('inc', $r)),
                'ndflYtd' => self::money($get('tytd', $r)), 'ndflMonth' => self::money($get('tmon', $r)),
                'benefits' => $benefits,
            ];
        }
        $book->disconnectWorksheets();
        if (! $rows) {
            throw new RowError(t('accounting.filed.errors.empty'));
        }

        return ['period' => $period, 'file' => $name, 'rows' => $rows];
    }

    /** Столбцы по тексту заголовков (строки 10–11 формы). */
    private static function columns(Worksheet $ws): array
    {
        $max = min(60, Coordinate::columnIndexFromString($ws->getHighestColumn()));
        $h = [];
        $h11 = [];
        for ($c = 1; $c <= $max; $c++) {
            $a = mb_strtolower(preg_replace('/\s+/u', ' ', (string) self::plain($ws->getCell(Coordinate::stringFromColumnIndex($c).'10')->getValue())));
            $b = mb_strtolower(preg_replace('/\s+/u', ' ', (string) self::plain($ws->getCell(Coordinate::stringFromColumnIndex($c).'11')->getValue())));
            $h[$c] = trim($a.' '.$b);
            $h11[$c] = trim($b);
        }
        $col = [];
        foreach ($h as $c => $t) {
            $map = ['name' => 'ф.и.ш', 'pos' => 'лавозими', 'date' => 'иш ўрни ташкил', 'res' => 'жисмоний шахс тури', 'status' => 'ходимнинг ҳолати',
                'contract' => 'шартнома тури', 'rate' => 'иш вақти'];
            foreach ($map as $k => $needle) {
                if (! isset($col[$k]) && str_contains($t, $needle)) {
                    $col[$k] = $c;
                }
            }
            if (! isset($col['pinfl']) && str_contains($t, 'жш шир') && ! str_contains($t, 'амалдаги')) {
                $col['pinfl'] = $c;
            }
            if (! isset($col['wp']) && preg_match('/иш ўрни \(1/u', $t)) {
                $col['wp'] = $c;
            }
            if (! isset($col['ytd']) && str_contains($t, 'жами ҳисобланган меҳнатга')) {
                $col['ytd'] = $c;
                $col['inc'] = $c + 1;
            }
            if (! isset($col['tytd']) && str_contains($t, 'жами ҳисобланган солиқ суммаси')) {
                $col['tytd'] = $c;
                $col['tmon'] = $c + 1;
            }
        }
        // Льготы: «имтиёз рақами», затем «жами» и «за месяц» (в 2026 до трёх групп)
        foreach ($h11 as $c => $t) {
            if (str_starts_with($t, 'имтиёз')) {
                $col['ben'][] = $c;
            }
        }
        if (empty($col['ben'])) {
            foreach ($h as $c => $t) {
                if (str_starts_with($t, 'имтиёз')) {
                    $col['ben'][] = $c;
                    break;
                }
            }
        }

        return $col;
    }

    private static function plain(mixed $v): mixed
    {
        return $v instanceof RichText ? $v->getPlainText() : $v;
    }

    private static function money(mixed $v): string
    {
        $s = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string) self::plain($v));

        return is_numeric($s) ? (string) BigDecimal::of($s)->toScale(2, RoundingMode::HalfUp) : '0.00';
    }

    private static function date(mixed $v): ?string
    {
        $v = self::plain($v);
        if ($v === null || $v === '') {
            return null;
        }
        try {
            if (is_numeric($v)) {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $v))->format('Y-m-d');
            }
            if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', trim((string) $v), $m)) {
                return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }
        } catch (Throwable) {
        }

        return null;
    }

    /** ФИО как в отчёте: пробелы и апострофы приведены к одному виду. */
    private static function name(string $v): string
    {
        $v = trim((string) preg_replace('/\s+/u', ' ', $v));

        return str_replace(['ʻ', 'ʼ', '‘', '’', '`', '´'], "'", $v);
    }

    // ─────────────────────────── Сальдо НДФЛ ───────────────────────────

    /**
     * Что известно из сданных отчётов по месяцам.
     *
     * @return array{filed:array<string,array{people:int,income:BigDecimal,ndflYtd:BigDecimal,ndflMonthFiled:BigDecimal,due:BigDecimal}>,lastByYear:array<string,string>}
     */
    public static function map(): array
    {
        $rows = AccTaxFiling::query()->selectRaw('period, COUNT(*) as n, SUM(income_month) as inc, SUM(ndfl_ytd) as ny, SUM(ndfl_month) as nm')
            ->groupBy('period')->orderBy('period')->get();
        $filed = [];
        $last = [];
        $prev = ['year' => null, 'ytd' => BigDecimal::zero()];
        foreach ($rows as $r) {
            $year = substr($r->period, 0, 4);
            $ytd = Accounting::dec($r->ny);
            $before = $prev['year'] === $year ? $prev['ytd'] : BigDecimal::zero();
            $filed[$r->period] = ['people' => (int) $r->n, 'income' => Accounting::dec($r->inc), 'ndflYtd' => $ytd,
                'ndflMonthFiled' => Accounting::dec($r->nm), 'due' => $ytd->minus($before)];
            $last[$year] = $r->period;
            $prev = ['year' => $year, 'ytd' => $ytd];
        }

        return ['filed' => $filed, 'lastByYear' => $last];
    }

    /** НДФЛ к уплате за месяц по сданному отчёту: сумма; 0 — месяц покрыт следующим отчётом года; null — отчётов нет (считается по ведомости/окладам). */
    public static function due(string $period, ?array $map = null): ?BigDecimal
    {
        $map ??= self::map();
        if (isset($map['filed'][$period])) {
            return $map['filed'][$period]['due'];
        }
        $last = $map['lastByYear'][substr($period, 0, 4)] ?? null;

        return $last !== null && $period < $last ? BigDecimal::zero() : null;
    }

    /** Начисленный доход за год по сданным отчётам в месяцах без ведомости зарплаты (для финансовых результатов). */
    public static function incomeWithoutPayroll(int $year, array $payrollPeriods): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach (self::map()['filed'] as $period => $f) {
            if (str_starts_with($period, $year.'-') && ! in_array($period, $payrollPeriods, true)) {
                $sum = $sum->plus($f['income']);
            }
        }

        return $sum;
    }

    // ─────────────────────────── Люди по отчётам ───────────────────────────

    /**
     * Записи по ПИНФЛ из строк отчётов: загруженные плюс сохранённые за остальные месяцы.
     *
     * @param  array<string,array>  $upload  месяцы из read()
     * @return array<string,list<array>>
     */
    private static function entries(array $upload): array
    {
        $out = [];
        $add = function (string $period, array $r) use (&$out) {
            if (preg_match('/^\d{14}$/', (string) $r['pinfl'])) {
                $out[$r['pinfl']][] = ['period' => $period, 'name' => $r['name'], 'position' => $r['position'], 'status' => (int) $r['status'],
                    'income' => (string) $r['incomeMonth'], 'workStart' => $r['workStart'], 'rate' => (string) $r['rate'], 'resident' => (int) $r['resident'],
                    'contract' => (int) $r['contract'], 'workplace' => (int) $r['workplace']];
            }
        };
        foreach (AccTaxFiling::whereNotIn('period', array_keys($upload) ?: ['0000-00'])->orderBy('period')->get() as $f) {
            $add($f->period, ['pinfl' => $f->pinfl, 'name' => $f->full_name, 'position' => $f->position, 'status' => $f->status, 'incomeMonth' => (string) $f->income_month,
                'workStart' => $f->work_start?->toDateString(), 'rate' => '1', 'resident' => 1, 'contract' => 1, 'workplace' => 1]);
        }
        foreach ($upload as $period => $m) {
            foreach ($m['rows'] as $r) {
                $add($period, $r);
            }
        }
        foreach ($out as &$list) {
            usort($list, fn ($a, $b) => strcmp($a['period'], $b['period']));
        }

        return $out;
    }

    /**
     * Человек по записям отчётов: данные для карточки, оклад по истории доходов, приём и увольнение.
     * Без месяцев с доходом человек не создаётся (пробные строки в отчётах).
     */
    public static function derive(array $entries, string $latestPeriod): ?array
    {
        $paid = array_values(array_filter($entries, fn ($e) => BigDecimal::of($e['income'])->isPositive()));
        if (! $paid) {
            return null;
        }
        $last = end($entries);
        $firstPaid = $paid[0];
        $lastPaid = end($paid);
        $names = array_column($entries, 'name');
        $latin = array_values(array_filter($names, fn ($n) => TaxReport::isLatin(mb_strtoupper($n))));
        $name = $latin ? end($latin) : end($names);
        $hired = $firstPaid['period'].'-01';
        if ($firstPaid['workStart'] && substr($firstPaid['workStart'], 0, 7) === $firstPaid['period'] && $firstPaid['workStart'] > $hired) {
            $hired = $firstPaid['workStart'];
        }
        $gone = $last['status'] === 2 || $last['period'] < $latestPeriod;
        $fired = $gone ? CarbonImmutable::createFromFormat('!Y-m', $lastPaid['period'])->endOfMonth()->toDateString() : null;

        // Оклад: смена принимается, если новая сумма повторилась в следующем месяце с доходом (разовые доплаты и неполные месяцы не в счёт);
        // последний неполный месяц перед увольнением оклад не меняет
        $history = [];
        $cur = null;
        foreach ($paid as $i => $e) {
            $v = (string) BigDecimal::of($e['income'])->toScale(2);
            if ($cur !== null && BigDecimal::of($v)->isEqualTo($cur)) {
                continue;
            }
            $next = $paid[$i + 1]['income'] ?? null;
            $confirmed = $next !== null && BigDecimal::of($next)->isEqualTo($v);
            if ($confirmed || ($next === null && ($cur === null || ! $gone))) {
                $history[] = ['from' => $e['period'], 'amount' => $v];
                $cur = $v;
            }
        }
        if (! $history) {
            $history[] = ['from' => $firstPaid['period'], 'amount' => (string) BigDecimal::of($firstPaid['income'])->toScale(2)];
        }

        return [
            'name' => $name, 'position' => (string) (array_values(array_filter(array_reverse(array_column($entries, 'position'))))[0] ?? ''),
            'hired' => $hired, 'fired' => $fired, 'active' => $fired === null, 'history' => $history, 'salary' => end($history)['amount'],
            'months' => count($paid), 'first' => $firstPaid['period'], 'lastPaid' => $lastPaid['period'],
            'rate' => $last['rate'], 'resident' => $last['resident'], 'contract' => $last['contract'], 'workplace' => $last['workplace'],
        ];
    }

    /** Имя для карточки: заглавные латинские буквы — как слова с большой буквы. */
    private static function display(string $name): string
    {
        return $name === mb_strtoupper($name) ? mb_convert_case($name, MB_CASE_TITLE, 'UTF-8') : $name;
    }

    // ─────────────────────────── Предпросмотр и загрузка ───────────────────────────

    /** Что сделает загрузка: месяцы, расхождения, люди (новые и дополняемые). */
    public static function plan(array $months, array $acts = []): array
    {
        $latest = max(array_merge(array_keys($months), array_keys(self::map()['filed'])) ?: ['0000-00']);
        $perMonth = [];
        $prev = ['year' => null, 'ytd' => BigDecimal::zero()];
        $stored = self::map()['filed'];
        $sumStr = fn (array $rows, string $k) => array_reduce($rows, fn (BigDecimal $c, $r) => $c->plus($r[$k]), BigDecimal::zero());
        $ytdOf = function (string $p) use ($months, $stored, $sumStr) {
            return isset($months[$p]) ? $sumStr($months[$p]['rows'], 'ndflYtd') : ($stored[$p]['ndflYtd'] ?? null);
        };
        $periods = array_unique(array_merge(array_keys($months), array_keys($stored)));
        sort($periods);
        $warnings = [];
        foreach ($periods as $p) {
            $y = substr($p, 0, 4);
            $ytd = $ytdOf($p);
            $before = $prev['year'] === $y ? $prev['ytd'] : BigDecimal::zero();
            $due = $ytd->minus($before);
            $prev = ['year' => $y, 'ytd' => $ytd];
            if (! isset($months[$p])) {
                continue;
            }
            $rows = $months[$p]['rows'];
            $filedMonth = $sumStr($rows, 'ndflMonth');
            $perMonth[$p] = ['file' => $months[$p]['file'], 'people' => count($rows), 'income' => $sumStr($rows, 'incomeMonth'), 'ndflMonth' => $filedMonth, 'ndflYtd' => $ytd, 'due' => $due,
                'mismatch' => $due->minus($filedMonth)->abs()->isGreaterThan(1)];
            if ($perMonth[$p]['mismatch']) {
                $warnings[] = ['period' => $p, 'filed' => (string) $filedMonth->toScale(2), 'due' => (string) $due->toScale(2)];
            }
        }
        $gaps = [];
        if ($periods) {
            for ($m = CarbonImmutable::createFromFormat('!Y-m', $periods[0]); $m->format('Y-m') <= end($periods); $m = $m->addMonthNoOverflow()) {
                if (! in_array($m->format('Y-m'), $periods, true)) {
                    $gaps[] = $m->format('Y-m');
                }
            }
        }

        $people = [];
        $noIncome = 0;
        $existing = AccPerson::whereNotNull('pinfl')->get()->keyBy('pinfl');
        foreach (self::entries($months) as $pinfl => $entries) {
            $d = self::derive($entries, $latest);
            if (! $d) {
                $noIncome++;

                continue;
            }
            $people[] = $d + ['pinfl' => $pinfl, 'exists' => $existing->has($pinfl), 'existingName' => $existing[$pinfl]->full_name ?? null];
        }
        usort($people, fn ($a, $b) => strcmp($a['first'], $b['first']));

        return ['months' => $perMonth, 'acts' => $acts, 'warnings' => $warnings, 'gaps' => $gaps, 'people' => $people, 'noIncomePeople' => $noIncome,
            'first' => $periods[0] ?? null, 'last' => $periods ? end($periods) : null];
    }

    /** Разбор файлов → токен предпросмотра. */
    public static function upload(array $uploads): string
    {
        $r = self::read($uploads);
        if (! $r['months'] && ! $r['acts']) {
            throw new RowError($r['errors'][0] ?? t('accounting.filed.errors.nothing'));
        }

        return ImportStore::put(['entity' => self::ENTITY, 'user_id' => access()->id(), 'months' => $r['months'], 'acts' => $r['acts'], 'errors' => $r['errors']]);
    }

    public static function preview(string $token): ?array
    {
        return ImportStore::valid($token) ? ImportStore::get($token, self::ENTITY, (string) access()->id()) : null;
    }

    /** Загрузка отчётов: строки по месяцам заменяются, люди создаются или дополняются. Возвращает итог. */
    public static function confirm(string $token): ?array
    {
        $data = ImportStore::valid($token) ? ImportStore::claim($token, self::ENTITY, (string) access()->id()) : null;
        if (! $data) {
            return null;
        }
        $result = self::apply($data['months'], $data['acts'] ?? []);
        ImportStore::finish($token, $result);

        return $result;
    }

    public static function apply(array $months, array $acts = []): array
    {
        $actor = access(false)?->id();

        return DB::transaction(function () use ($months, $acts, $actor) {
            foreach ($acts as $year => $a) {
                AccTaxAct::where('year', $year)->delete();
                foreach ($a['rows'] as $r) {
                    AccTaxAct::create(['year' => $year, 'tax_code' => $r['code'], 'tax_name' => $r['name'], 'as_of' => $a['asOf'], 'year_end' => $a['yearEnd'],
                        'opening_debt' => $r['openingDebt'], 'opening_overpay' => $r['openingOverpay'], 'closing_debt' => $r['closingDebt'], 'closing_overpay' => $r['closingOverpay'],
                        'closing_penalty' => $r['closingPenalty'], 'accrued' => $r['accrued'], 'accrued_penalty' => $r['accruedPenalty'], 'paid' => $r['paid'], 'refunded' => $r['refunded'],
                        'source_file' => $a['file'], 'imported_by_id' => $actor]);
                }
            }
            $rowsCount = 0;
            foreach ($months as $period => $m) {
                AccTaxFiling::where('period', $period)->delete();
                foreach ($m['rows'] as $i => $r) {
                    AccTaxFiling::create(['period' => $period, 'row_no' => $i + 1, 'pinfl' => $r['pinfl'] ?: null, 'full_name' => mb_substr($r['name'], 0, 191),
                        'position' => $r['position'] ?: null, 'status' => $r['status'], 'work_start' => $r['workStart'],
                        'income_ytd' => $r['incomeYtd'], 'income_month' => $r['incomeMonth'], 'ndfl_ytd' => $r['ndflYtd'], 'ndfl_month' => $r['ndflMonth'],
                        'benefits' => $r['benefits'] ?: null, 'source_file' => $m['file'], 'imported_by_id' => $actor]);
                    $rowsCount++;
                }
            }
            $latest = (string) AccTaxFiling::max('period');
            $created = 0;
            $updated = 0;
            $existing = AccPerson::whereNotNull('pinfl')->get()->keyBy('pinfl');
            foreach (self::entries([]) as $pinfl => $entries) {
                $d = self::derive($entries, $latest);
                if (! $d) {
                    continue;
                }
                $person = $existing[$pinfl] ?? null;
                $person ? $updated++ : $created++;
                $existing[$pinfl] = self::savePerson($person, $pinfl, $d, $latest);
            }
            foreach ($existing as $pinfl => $person) {
                AccTaxFiling::where('pinfl', $pinfl)->whereNull('person_id')->update(['person_id' => $person->id]);
            }
            $periods = array_keys($months);
            Audit::log('accounting.filings.import', 'acc_tax_filing', null, ['import' => ['old' => null, 'new' => [
                'periods' => count($periods), 'from' => $periods[0] ?? null, 'to' => end($periods) ?: null, 'rows' => $rowsCount, 'peopleCreated' => $created, 'peopleUpdated' => $updated,
                'acts' => array_keys($acts),
            ]]]);

            return ['months' => count($periods), 'acts' => count($acts), 'rows' => $rowsCount, 'created' => $created, 'updated' => $updated, 'from' => $periods[0] ?? null, 'to' => end($periods) ?: null];
        });
    }

    /**
     * Новый человек — по данным отчётов целиком. Существующий дополняется: пустые поля заполняются, приём не позже
     * первого месяца с доходом, увольнение — если не было, история окла­дов — только за месяцы до его первой записи.
     */
    private static function savePerson(?AccPerson $p, string $pinfl, array $d, string $latest): AccPerson
    {
        $name = self::display($d['name']);
        $latin = TaxReport::isLatin(mb_strtoupper($d['name'])) ? mb_strtoupper($d['name']) : null;
        if (! $p) {
            $p = AccPerson::create(['full_name' => mb_substr($name, 0, 191), 'passport_name' => $latin ? mb_substr($latin, 0, 191) : null, 'pinfl' => $pinfl,
                'position' => $d['position'] ?: null, 'resident_type' => $d['resident'], 'contract_type' => $d['contract'], 'work_rate' => self::rate($d['rate']),
                'workplace_type' => $d['workplace'], 'monthly_salary' => $d['salary'], 'hired_at' => $d['hired'], 'fired_at' => $d['fired'],
                'is_active' => $d['active'], 'comment' => t('accounting.filed.personComment')]);
            foreach ($d['history'] as $h) {
                AccPersonSalary::create(['person_id' => $p->id, 'effective_from' => $h['from'], 'amount' => $h['amount'], 'created_by_id' => access(false)?->id()]);
            }
            Audit::log('accounting.person.create', 'acc_person', $p->id, ['pinfl' => ['old' => null, 'new' => $pinfl], 'source' => ['old' => null, 'new' => 'filed-reports']]);

            return $p;
        }
        $changes = [];
        $set = function (string $field, mixed $value) use ($p, &$changes) {
            $old = $p->{$field};
            $p->{$field} = $value;
            $changes[$field] = ['old' => $old instanceof \DateTimeInterface ? $old->format('Y-m-d') : $old, 'new' => $value];
        };
        if (! $p->passport_name && $latin) {
            $set('passport_name', mb_substr($latin, 0, 191));
        }
        if (! $p->position && $d['position']) {
            $set('position', $d['position']);
        }
        if (! $p->hired_at || $p->hired_at->toDateString() > $d['hired']) {
            $set('hired_at', $d['hired']);
        }
        if (! $p->fired_at && $d['fired']) {
            $set('fired_at', $d['fired']);
            $set('is_active', false);
        } elseif ($d['active'] && ! $p->is_active && $d['lastPaid'] === $latest) {
            $set('is_active', true);
        }
        if ($changes) {
            $p->save();
            Audit::log('accounting.person.update', 'acc_person', $p->id, $changes + ['source' => ['old' => null, 'new' => 'filed-reports']]);
        }
        $firstOwn = AccPersonSalary::where('person_id', $p->id)->min('effective_from');
        foreach ($d['history'] as $h) {
            if ($firstOwn === null || $h['from'] < $firstOwn) {
                AccPersonSalary::updateOrCreate(['person_id' => $p->id, 'effective_from' => $h['from']], ['amount' => $h['amount'], 'created_by_id' => access(false)?->id()]);
            }
        }

        return $p;
    }

    private static function rate(string $v): string
    {
        $n = is_numeric($v) ? (float) $v : 1.0;

        return (string) BigDecimal::of((string) min(9.99, max(0.01, $n)))->toScale(2);
    }
}
