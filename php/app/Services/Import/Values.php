<?php

namespace App\Services\Import;

use App\Support\Format;
use App\Support\Lang;
use Brick\Math\BigDecimal;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use Throwable;

/**
 * Разбор значений ячеек импорта: даты (YYYY-MM-DD, DD.MM.YYYY, ячейка даты Excel), время HH:MM,
 * суммы с пробелами и запятыми, значения справочников и перечислений на любом из трёх языков.
 * Ошибка — RowError с понятной причиной на языке пользователя.
 */
final class Values
{
    /** Заголовок/значение для сравнения: без регистра, «*», лишних пробелов; ʻ ʼ ‘ ’ ` → '. */
    public static function norm(?string $v): string
    {
        $s = str_replace(['ʻ', 'ʼ', '‘', '’', '`', 'ʹ', "\u{00A0}"], ["'", "'", "'", "'", "'", "'", ' '], (string) $v);
        $s = mb_strtolower(trim(str_replace('*', '', $s)));

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * Сырое значение ячейки → строка (или null). Ячейки дат и времени Excel хранят число —
     * для колонок типа date/time оно переводится в YYYY-MM-DD / HH:MM.
     */
    public static function cell(mixed $v, string $type = 'text'): ?string
    {
        if ($v === null) {
            return null;
        }
        if ($v instanceof DateTimeInterface) {
            return $type === 'time' ? $v->format('H:i') : $v->format('Y-m-d');
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v) || is_float($v)) {
            if ($type === 'date' && $v > 0 && $v < 2958466) {
                try {
                    return XlsDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
                } catch (Throwable) {
                    return (string) $v;
                }
            }
            if ($type === 'time' && $v >= 0 && $v < 1) {
                $minutes = (int) round($v * 1440);

                return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
            }

            return self::numberString($v);
        }
        $s = trim(str_replace("\u{00A0}", ' ', (string) $v));

        return $s === '' ? null : $s;
    }

    /** Число из Excel → строка без экспоненты и лишних нулей (телефон 998901234567.0 → «998901234567»). */
    private static function numberString(int|float $v): string
    {
        if (is_int($v)) {
            return (string) $v;
        }
        if (floor($v) === $v && abs($v) < 1e18) {
            return sprintf('%.0f', $v);
        }
        $s = rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');

        return $s === '' || $s === '-' ? '0' : $s;
    }

    /** Текст с ограничением длины. */
    public static function text(?string $v, int $max, string $column): ?string
    {
        $s = $v === null ? '' : trim($v);
        if ($s === '') {
            return null;
        }
        if (mb_strlen($s) > $max) {
            throw new RowError(t('import.errors.tooLong', ['column' => $column, 'max' => $max]));
        }

        return $s;
    }

    /** Сумма: «9 000 000,50», «9,000,000.50», «9000000» → «9000000.50»/«9000000». Не отрицательная, до 2 знаков. */
    public static function money(?string $v, string $column): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        $s = str_replace([' ', "\u{00A0}", "\u{202F}", "'"], '', trim($v));
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = str_replace(',', '', $s);
        } elseif (str_contains($s, ',')) {
            $s = preg_match('/^\d{1,3}(,\d{3})+$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        }
        if (! preg_match('/^\d{1,16}(\.\d+)?$/', $s)) {
            throw new RowError(t('import.errors.money', ['column' => $column, 'value' => $v]));
        }
        $d = BigDecimal::of($s)->strippedOfTrailingZeros();
        if ($d->getScale() > 2) {
            throw new RowError(t('import.errors.money', ['column' => $column, 'value' => $v]));
        }

        return (string) $d;
    }

    /** Дата: YYYY-MM-DD, DD.MM.YYYY (также D.M.YYYY и DD/MM/YYYY). */
    public static function date(?string $v, string $column): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        $s = trim($v);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?$/', $s, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})(?:\s+\d{1,2}:\d{2}(?::\d{2})?)?$/', $s, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            throw new RowError(t('import.errors.date', ['column' => $column, 'value' => $v]));
        }
        if (! checkdate($mo, $d, $y) || $y < 2000 || $y > 2100) {
            throw new RowError(t('import.errors.date', ['column' => $column, 'value' => $v]));
        }

        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    /** Время HH:MM (или H:MM, HH:MM:SS) по Ташкенту. */
    public static function time(?string $v, string $column): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        if (! preg_match('/^(\d{1,2})[:.](\d{2})(?::\d{2})?$/', trim($v), $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            throw new RowError(t('import.errors.time', ['column' => $column, 'value' => $v]));
        }

        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }

    public static function email(?string $v, string $column): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        $s = mb_strtolower(trim($v));
        if (mb_strlen($s) > 254 || ! filter_var($s, FILTER_VALIDATE_EMAIL)) {
            throw new RowError(t('import.errors.email', ['column' => $column, 'value' => $v]));
        }

        return $s;
    }

    /**
     * Телефон — как в формах CRM: цифры, +, пробелы, дефисы, скобки; до 30 символов.
     * Excel и выгрузки из других систем добавляют к номеру ' (признак текста), кавычки, «=», точки
     * или пишут несколько номеров через запятую — такое убираем, из нескольких номеров берём первый.
     */
    public static function phone(?string $v, string $column): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        $s = trim(str_replace(["'", '"', '`', '‘', '’', 'ʻ', 'ʼ', '«', '»', '='], '', $v));
        $s = trim(preg_split('/[,;\/\n]+/', $s)[0] ?? '');
        $s = trim((string) preg_replace('/(?<=\d)\.(?=\d)/', ' ', $s));
        if ($s === '') {
            return null;
        }
        if (mb_strlen($s) > 30 || ! preg_match('/^[+0-9\s\-()]*$/', $s)) {
            throw new RowError(t('import.errors.phone', ['column' => $column, 'value' => $v]));
        }

        return $s;
    }

    /**
     * Значение перечисления: код (PRESENT) или подпись на любом языке (t($prefix.'.'.код)).
     *
     * @param  list<string>  $codes
     * @param  array<string,string>  $extra  дополнительные слова → код
     */
    public static function enum(?string $v, array $codes, string $prefix, string $column, array $extra = []): ?string
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        $n = self::norm($v);
        foreach ($codes as $code) {
            if ($n === mb_strtolower($code)) {
                return $code;
            }
            foreach (Lang::LOCALES as $locale) {
                if (Lang::has($prefix.'.'.$code, $locale) && $n === self::norm(Lang::get($prefix.'.'.$code, [], $locale))) {
                    return $code;
                }
            }
        }
        foreach ($extra as $word => $code) {
            if ($n === self::norm($word)) {
                return $code;
            }
        }
        throw new RowError(t('import.errors.notInList', ['column' => $column, 'value' => $v]));
    }

    /**
     * Запись справочника по коду или названию на любом языке (name_ru / name_uz / name_en).
     *
     * @param  iterable<Model>  $rows
     */
    public static function ref(?string $v, iterable $rows, string $column): ?Model
    {
        if ($v === null || trim($v) === '') {
            return null;
        }
        $n = self::norm($v);
        foreach ($rows as $row) {
            $names = [$row->code ?? null, $row->name_ru ?? null, $row->name_uz ?? null, $row->name_en ?? null, $row->name ?? null];
            foreach ($names as $name) {
                if ($name !== null && $name !== '' && self::norm($name) === $n) {
                    return $row;
                }
            }
        }
        throw new RowError(t('import.errors.notInList', ['column' => $column, 'value' => $v]));
    }

    /** Номер записи: «C-00012», «c12», «12» → 12; другое — null. */
    public static function number(?string $v, string $prefix): ?int
    {
        if ($v === null) {
            return null;
        }
        if (preg_match('/^\s*(?:'.preg_quote($prefix, '/').'\s*-?\s*)?0*(\d{1,9})\s*$/iu', $v, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /** Сегодня по Ташкенту (YYYY-MM-DD). */
    public static function today(): string
    {
        return Format::today()->toDateString();
    }
}
