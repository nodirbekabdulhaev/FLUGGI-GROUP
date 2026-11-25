<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Лист выгрузки: колонки (header, width, money, date) и строки. Ячейка — строка, число,
 * CarbonImmutable (момент уже переведён в Ташкент или календарная дата) или null.
 */
final class Sheet
{
    /** @param  list<array{header:string,width?:int,money?:bool,date?:bool}>  $columns */
    public function __construct(public string $title, public array $columns, public array $rows = []) {}

    /** Защита от формул в ячейках (CSV/Excel injection). */
    private static function safe(string $s): string
    {
        return preg_match('/^[=+\-@\t\r]/', $s) && ! preg_match('/^-?\d/', $s) ? "'".$s : $s;
    }

    private static function fmt(mixed $v, array $col): string
    {
        if ($v === null) {
            return '';
        }
        if ($v instanceof CarbonImmutable) {
            return ! empty($col['date']) && $v->format('H:i:s') === '00:00:00' ? $v->format('Y-m-d') : $v->format('Y-m-d H:i');
        }

        return is_float($v) ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') : (string) $v;
    }

    /** CSV для Excel: UTF-8 с BOM, разделитель «;» (так Excel в русской локали открывает без мастера). */
    public function toCsv(): string
    {
        $esc = fn (string $s) => preg_match('/[";\n\r]/', $s) ? '"'.str_replace('"', '""', $s).'"' : $s;
        $lines = [implode(';', array_map(fn ($c) => $esc($c['header']), $this->columns))];
        foreach ($this->rows as $r) {
            $cells = [];
            foreach ($r as $i => $v) {
                $cells[] = $esc(self::safe(self::fmt($v, $this->columns[$i] ?? [])));
            }
            $lines[] = implode(';', $cells);
        }

        return "\u{FEFF}".implode("\r\n", $lines)."\r\n";
    }

    /** Лист Excel: шапка жирная и закреплена, автофильтр, форматы денег и дат. */
    public function addTo(Spreadsheet $book, bool $filter = true): void
    {
        $ws = $book->getSheetCount() === 1 && $book->getSheet(0)->getHighestRow() === 1 && $book->getSheet(0)->getTitle() === 'Worksheet'
            ? $book->getSheet(0) : $book->createSheet();
        $ws->setTitle(mb_substr(str_replace(['*', ':', '/', '\\', '?', '[', ']'], ' ', $this->title), 0, 31));
        foreach ($this->columns as $i => $c) {
            $ws->setCellValue([$i + 1, 1], $c['header']);
            $ws->getColumnDimensionByColumn($i + 1)->setWidth($c['width'] ?? max(12, mb_strlen($c['header']) + 2));
        }
        $last = count($this->columns);
        $ws->getStyle([1, 1, $last, 1])->getFont()->setBold(true);
        $ws->freezePane('A2');
        if ($filter && $last > 1) {
            $ws->setAutoFilter([1, 1, $last, 1]);
        }
        foreach ($this->rows as $ri => $r) {
            foreach ($r as $ci => $v) {
                $cell = [$ci + 1, $ri + 2];
                if ($v === null) {
                    continue;
                }
                if ($v instanceof CarbonImmutable) {
                    $ws->setCellValue($cell, XlsDate::PHPToExcel($v->format('Y-m-d H:i:s')));
                    $ws->getStyle($cell)->getNumberFormat()->setFormatCode($v->format('H:i:s') === '00:00:00' ? 'dd.mm.yyyy' : 'dd.mm.yyyy hh:mm');
                } elseif (is_int($v) || is_float($v)) {
                    $ws->setCellValue($cell, $v);
                    if (! empty($this->columns[$ci]['money'])) {
                        $ws->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.00');
                    }
                } else {
                    $ws->setCellValueExplicit($cell, self::safe((string) $v), DataType::TYPE_STRING);
                }
            }
        }
    }

    /** Excel-файл из листов. */
    public static function xlsx(array $sheets): string
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator(brand()->name());
        foreach ($sheets as $s) {
            $s->addTo($book);
        }
        $book->setActiveSheetIndex(0);
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($book))->save($tmp);
        $data = file_get_contents($tmp);
        @unlink($tmp);

        return $data;
    }
}
