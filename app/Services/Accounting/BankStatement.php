<?php

namespace App\Services\Accounting;

use App\Services\Import\ImportFile;
use App\Services\Import\RowError;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Разбор банковской выписки (XLSX, XLS или CSV). Формат определяется по заголовкам таблицы:
 *  - CLASSIC — «Выписка лицевых счетов»: шапка с номером счёта и остатками, колонки «Обороты по дебету»
 *    (деньги ушли со счёта) и «Обороты по кредиту» (пришли), строка «Итого» в конце;
 *  - APP — выгрузка операций из приложения банка: «Turi» (Kirim/Chiqim), «Holati» (берутся только проведённые),
 *    «Summa», «Sana», плательщик и получатель. Номера счёта в файле нет — счёт выбирает пользователь.
 * Заголовки сравниваются без регистра, пробелов, переносов строк и знаков (русский и узбекский варианты).
 */
final class BankStatement
{
    public const EXTENSIONS = ['xlsx', 'xls', 'csv'];

    public const MAX_ROWS = 2000;

    /** Подписи колонок (нормализованные): поле → варианты. */
    private const CLASSIC = [
        'date' => ['датадокумента', 'hujjatsanasi', 'датаоперации', 'operatsiyasanasi', 'датаобработки', 'дата', 'sana'],
        'doc' => ['док', 'номердокумента', 'номердок', 'hujjatraqami', 'hujjat', 'ndok'],
        'name' => ['наименованиесчета', 'наименование', 'hisobnomi', 'nomi', 'контрагент'],
        'inn' => ['инн', 'stir', 'inn'],
        'account' => ['счета', 'номерсчета', 'hisobraqami', 'счет'],
        'debit' => ['оборотыподебету', 'дебет', 'debetaylanmasi', 'debet', 'дебетобороты'],
        'credit' => ['оборотыпокредиту', 'кредит', 'kreditaylanmasi', 'kredit', 'кредитобороты'],
        'purpose' => ['назначениеплатежа', 'назначение', 'tolovmaqsadi', 'tolovnimaqsadi', 'maqsad'],
    ];

    private const APP = [
        'doc' => ['raqami', 'raqam', 'номер', 'nomer'],
        'type' => ['turi', 'тип', 'типоперации', 'operatsiyaturi'],
        'status' => ['holati', 'статус', 'состояние', 'holat'],
        'amount' => ['summa', 'сумма'],
        'date' => ['sana', 'дата', 'датаоперации'],
        'payer' => ['tolovchi', 'плательщик'],
        'receiver' => ['qabulqiluvchi', 'получатель'],
        'purpose' => ['tafsilotlar', 'детали', 'назначение', 'назначениеплатежа', 'izoh'],
    ];

    private const IN_TYPES = ['kirim', 'приход', 'поступление', 'зачисление', 'входящий', 'credit', 'in'];

    private const OUT_TYPES = ['chiqim', 'расход', 'списание', 'исходящий', 'debit', 'out'];

    private const DONE = ['otkazilgan', 'проведен', 'проведено', 'исполнен', 'исполнено', 'выполнен', 'выполнено', 'completed', 'success', 'bajarilgan'];

    /** Нормализация подписи: нижний регистр, ё → е, только буквы и цифры. */
    public static function norm(mixed $v): string
    {
        $s = mb_strtolower(trim((string) $v));
        $s = str_replace(['ё', 'ʻ', 'ʼ', '‘', '’', '`', "'"], ['е', '', '', '', '', '', ''], $s);

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
    }

    /**
     * @return array{format:string,accountNumber:?string,opening:?string,closing:?string,from:?string,to:?string,
     *   rows:list<array{n:int,date:string,direction:string,amount:string,doc:?string,counterparty:?string,inn:?string,cpAccount:?string,purpose:?string}>,
     *   errors:list<array{n:int,message:string}>,notCompleted:int}
     */
    public static function read(string $path, string $ext): array
    {
        $ext = mb_strtolower($ext);
        if (! in_array($ext, self::EXTENSIONS, true)) {
            throw new RowError(t('import.errors.fileType'));
        }
        foreach (self::sheets($path, $ext) as $matrix) {
            if ($parsed = self::parse($matrix)) {
                return $parsed;
            }
        }
        throw new RowError(t('accounting.bank.errors.format'));
    }

    /** @return list<list<list<mixed>>> листы → строки → ячейки */
    private static function sheets(string $path, string $ext): array
    {
        if ($ext === 'csv') {
            return [array_values(ImportFile::csv((string) file_get_contents($path)))];
        }
        try {
            $reader = IOFactory::createReader($ext === 'xls' ? 'Xls' : 'Xlsx');
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (Throwable) {
            throw new RowError(t('import.errors.unreadable'));
        }
        $out = [];
        foreach ($book->getAllSheets() as $ws) {
            if ($ws->getHighestDataRow() > self::MAX_ROWS * 3 + 50) {
                throw new RowError(t('import.errors.tooManyRows', ['max' => self::MAX_ROWS]));
            }
            $out[] = $ws->toArray(null, true, false, false);
        }
        $book->disconnectWorksheets();

        return $out;
    }

    /** Колонки по заголовку: поле → индекс (первое совпадение по порядку вариантов). */
    private static function columns(array $header, array $spec): array
    {
        $norm = array_map(fn ($c) => self::norm($c), $header);
        $cols = [];
        foreach ($spec as $field => $variants) {
            foreach ($variants as $v) {
                foreach ($norm as $i => $h) {
                    if ($h !== '' && ! in_array($i, $cols, true) && ($h === $v || (mb_strlen($v) >= 5 && str_starts_with($h, $v)))) {
                        $cols[$field] = $i;
                        break 2;
                    }
                }
            }
        }

        return $cols;
    }

    private static function parse(array $matrix): ?array
    {
        foreach ($matrix as $i => $row) {
            $cols = self::columns($row, self::CLASSIC);
            if (isset($cols['date'], $cols['debit'], $cols['credit'])) {
                return self::classic($matrix, $i, $cols);
            }
            $cols = self::columns($row, self::APP);
            if (isset($cols['type'], $cols['amount'], $cols['date'])) {
                return self::app($matrix, $i, $cols);
            }
            if ($i > 60) {
                break;
            }
        }

        return null;
    }

    private static function classic(array $matrix, int $headerIdx, array $cols): array
    {
        $out = ['format' => 'CLASSIC', 'accountNumber' => null, 'opening' => null, 'closing' => null, 'from' => null, 'to' => null, 'rows' => [], 'errors' => [], 'notCompleted' => 0];
        // Шапка: номер счёта, период, остатки на начало и конец
        for ($i = 0; $i < $headerIdx; $i++) {
            $cells = array_values(array_filter($matrix[$i], fn ($c) => $c !== null && trim((string) $c) !== ''));
            $line = implode(' ', array_map('strval', $cells));
            if (! $out['accountNumber'] && preg_match('/(сч[её]т|hisob|account)/iu', $line) && preg_match('/\b(\d{20})\b/', $line, $m)) {
                $out['accountNumber'] = $m[1];
            }
            if (! $out['from'] && preg_match('/(\d{2}\.\d{2}\.\d{4})\D+(\d{2}\.\d{2}\.\d{4})/u', $line, $m) && preg_match('/(^|\s)(за|с|dan|period|период)\s/iu', $line)) {
                $out['from'] = self::date($m[1]);
                $out['to'] = self::date($m[2]);
            }
            foreach ($cells as $k => $c) {
                $n = self::norm($c);
                if (in_array($n, ['началодня', 'kunboshi', 'входящийостаток', 'входящеесальдо'], true) && isset($cells[$k + 1])) {
                    $out['opening'] = self::amount($cells[$k + 1]);
                }
                if (in_array($n, ['конецдня', 'kunoxiri', 'исходящийостаток', 'исходящеесальдо'], true) && isset($cells[$k + 1])) {
                    $out['closing'] = self::amount($cells[$k + 1]);
                }
            }
        }
        $header = $matrix[$headerIdx];
        $count = count($matrix);
        for ($i = $headerIdx + 1; $i < $count; $i++) {
            $row = $matrix[$i];
            $n = $i + 1;
            $text = mb_strtolower(implode(' ', array_map(fn ($c) => (string) $c, $row)));
            if (trim($text) === '') {
                continue;
            }
            if ($row === $header) {
                continue;    // заголовок повторяется на каждой странице
            }
            $date = self::date($row[$cols['date']] ?? null);
            // «Итого за весь период» — конец таблицы (слово в назначении платежа строку с датой не обрывает)
            if ($date === null && (str_contains($text, 'итого') || str_contains($text, 'jami'))) {
                break;
            }
            $debit = self::amount($row[$cols['debit']] ?? null);
            $credit = self::amount($row[$cols['credit']] ?? null);
            if ($date === null && $debit === null && $credit === null) {
                continue;    // строка шапки страницы, номер страницы и т. п.
            }
            $hasDebit = $debit !== null && BigDecimal::of($debit)->isPositive();
            $hasCredit = $credit !== null && BigDecimal::of($credit)->isPositive();
            if ($date === null || $hasDebit === $hasCredit) {
                $out['errors'][] = ['n' => $n, 'message' => $date === null ? t('accounting.bank.errors.date') : t('accounting.bank.errors.amount')];

                continue;
            }
            $out['rows'][] = [
                'n' => $n, 'date' => $date, 'direction' => $hasDebit ? 'OUT' : 'IN', 'amount' => $hasDebit ? $debit : $credit,
                'doc' => self::text($row[$cols['doc'] ?? -1] ?? null), 'counterparty' => self::text($row[$cols['name'] ?? -1] ?? null),
                'inn' => self::text($row[$cols['inn'] ?? -1] ?? null), 'cpAccount' => self::text($row[$cols['account'] ?? -1] ?? null),
                'purpose' => self::text($row[$cols['purpose'] ?? -1] ?? null),
            ];
            self::limit($out);
        }

        return self::period($out);
    }

    private static function app(array $matrix, int $headerIdx, array $cols): array
    {
        $out = ['format' => 'APP', 'accountNumber' => null, 'opening' => null, 'closing' => null, 'from' => null, 'to' => null, 'rows' => [], 'errors' => [], 'notCompleted' => 0];
        $count = count($matrix);
        for ($i = $headerIdx + 1; $i < $count; $i++) {
            $row = $matrix[$i];
            $n = $i + 1;
            if (trim(implode('', array_map(fn ($c) => (string) $c, $row))) === '') {
                continue;
            }
            if (isset($cols['status']) && ! in_array(self::norm($row[$cols['status']] ?? ''), self::DONE, true)) {
                $out['notCompleted']++;

                continue;
            }
            $type = self::norm($row[$cols['type']] ?? '');
            $amount = self::amount($row[$cols['amount']] ?? null, signed: true);
            $direction = in_array($type, self::IN_TYPES, true) ? 'IN' : (in_array($type, self::OUT_TYPES, true) ? 'OUT' : null);
            if ($direction === null && $amount !== null && ! BigDecimal::of($amount)->isZero()) {
                $direction = BigDecimal::of($amount)->isNegative() ? 'OUT' : 'IN';
            }
            $date = self::date($row[$cols['date']] ?? null);
            if ($date === null || $amount === null || BigDecimal::of($amount)->isZero() || $direction === null) {
                $out['errors'][] = ['n' => $n, 'message' => $date === null ? t('accounting.bank.errors.date') : ($direction === null ? t('accounting.bank.errors.type') : t('accounting.bank.errors.amount'))];

                continue;
            }
            $amount = (string) BigDecimal::of($amount)->abs()->toScale(2);
            $payer = self::text($row[$cols['payer'] ?? -1] ?? null);
            $receiver = self::text($row[$cols['receiver'] ?? -1] ?? null);
            $out['rows'][] = [
                'n' => $n, 'date' => $date, 'direction' => $direction, 'amount' => $amount,
                'doc' => self::text($row[$cols['doc'] ?? -1] ?? null), 'counterparty' => $direction === 'IN' ? $payer : $receiver,
                'inn' => null, 'cpAccount' => null, 'purpose' => self::text($row[$cols['purpose'] ?? -1] ?? null),
            ];
            self::limit($out);
        }

        return self::period($out);
    }

    private static function limit(array $out): void
    {
        if (count($out['rows']) > self::MAX_ROWS) {
            throw new RowError(t('import.errors.tooManyRows', ['max' => self::MAX_ROWS]));
        }
    }

    /** Период по датам строк, если в шапке его нет. */
    private static function period(array $out): array
    {
        $dates = array_column($out['rows'], 'date');
        if ($dates) {
            $out['from'] ??= min($dates);
            $out['to'] ??= max($dates);
        }
        if (! $out['rows'] && ! $out['errors']) {
            throw new RowError(t('import.errors.noRows'));
        }

        return $out;
    }

    private static function text(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) preg_replace('/\s+/u', ' ', (string) $v));

        return $s === '' ? null : $s;
    }

    /** Сумма: число или строка «1 600 000,50»; без знака (кроме signed). null — не сумма. */
    public static function amount(mixed $v, bool $signed = false): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            $s = is_float($v) ? sprintf('%.2F', round($v, 2)) : (string) $v;
        } else {
            $s = str_replace(["\u{00A0}", "\u{202F}", ' ', "'"], '', trim((string) $v));
            if (str_contains($s, ',') && str_contains($s, '.')) {
                $s = strrpos($s, ',') > strrpos($s, '.') ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
            } else {
                $s = str_replace(',', '.', $s);
            }
        }
        if (! preg_match('/^-?\d{1,16}(\.\d+)?$/', $s)) {
            return null;
        }
        try {
            $d = BigDecimal::of($s)->toScale(2, RoundingMode::HalfUp);
        } catch (MathException) {
            return null;
        }

        return (string) ($signed ? $d : $d->abs());
    }

    /** Дата: ДД.ММ.ГГГГ, ГГГГ-ММ-ДД [ЧЧ:ММ], ДД/ММ/ГГГГ или дата Excel. */
    public static function date(mixed $v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if (is_int($v) || is_float($v)) {
            return $v > 20000 && $v < 80000 ? ExcelDate::excelToDateTimeObject($v)->format('Y-m-d') : null;
        }
        $s = trim((string) $v);
        if (preg_match('/^(\d{2})[.\/](\d{2})[.\/](\d{4})/', $s, $m)) {
            [$y, $mo, $d] = [(int) $m[3], (int) $m[2], (int) $m[1]];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        if (! checkdate($mo, $d, $y) || $y < 2000 || $y > 2100) {
            return null;
        }

        return CarbonImmutable::create($y, $mo, $d)->format('Y-m-d');
    }
}
