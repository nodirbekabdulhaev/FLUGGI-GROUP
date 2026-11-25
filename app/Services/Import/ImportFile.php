<?php

namespace App\Services\Import;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use Throwable;

/**
 * Чтение загруженного файла: XLSX (первый лист) или CSV (UTF-8, разделитель «;» или «,», BOM допускается).
 * Возвращает сырые строки — первая строка заголовков, номера строк как в Excel.
 * Файл читается из временного каталога PHP и нигде не сохраняется.
 */
final class ImportFile
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_ROWS = 2000;

    public const EXTENSIONS = ['xlsx', 'csv'];

    public const MIMES = [
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream', 'application/x-zip-compressed'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'text/comma-separated-values', 'application/octet-stream'],
    ];

    /**
     * @return array{header: list<mixed>, rows: list<array{n:int, cells:list<mixed>}>}
     */
    public static function read(UploadedFile $file): array
    {
        $ext = mb_strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::EXTENSIONS, true)) {
            throw new RowError(t('import.errors.fileType'));
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new RowError(t('import.errors.fileSize', ['mb' => 5]));
        }
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, self::MIMES[$ext], true)) {
            throw new RowError(t('import.errors.fileType'));
        }
        $matrix = $ext === 'xlsx' ? self::xlsx($file->getRealPath()) : self::csv((string) file_get_contents($file->getRealPath()));

        $header = null;
        $rows = [];
        foreach ($matrix as $n => $cells) {
            $empty = true;
            foreach ($cells as $c) {
                if ($c !== null && trim((string) ($c instanceof \DateTimeInterface ? 'x' : $c)) !== '') {
                    $empty = false;
                    break;
                }
            }
            if ($empty) {
                continue;
            }
            if ($header === null) {
                $header = $cells;

                continue;
            }
            $rows[] = ['n' => $n, 'cells' => $cells];
            if (count($rows) > self::MAX_ROWS) {
                throw new RowError(t('import.errors.tooManyRows', ['max' => self::MAX_ROWS]));
            }
        }
        if ($header === null) {
            throw new RowError(t('import.errors.noHeader'));
        }
        if (! $rows) {
            throw new RowError(t('import.errors.noRows'));
        }

        return ['header' => array_values($header), 'rows' => $rows];
    }

    /** @return array<int, list<mixed>> номер строки → ячейки */
    private static function xlsx(string $path): array
    {
        try {
            $reader = new XlsxReader;
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $book = $reader->load($path);
        } catch (Throwable) {
            throw new RowError(t('import.errors.unreadable'));
        }
        $ws = $book->getSheet(0);
        $lastRow = $ws->getHighestDataRow();
        // Защита от «раздутых» листов: строк заведомо больше лимита
        if ($lastRow > self::MAX_ROWS * 3 + 10) {
            throw new RowError(t('import.errors.tooManyRows', ['max' => self::MAX_ROWS]));
        }
        $lastCol = min(Coordinate::columnIndexFromString($ws->getHighestDataColumn()), 60);
        $out = [];
        for ($r = 1; $r <= $lastRow; $r++) {
            $cells = [];
            for ($c = 1; $c <= $lastCol; $c++) {
                $cell = $ws->getCell([$c, $r]);
                $v = $cell->getValue();
                if (is_string($v) && str_starts_with($v, '=')) {
                    try {
                        $v = $cell->getCalculatedValue();
                    } catch (Throwable) {
                        $v = $cell->getOldCalculatedValue();
                    }
                } elseif ($v instanceof RichText) {
                    $v = $v->getPlainText();
                }
                $cells[] = $v;
            }
            $out[$r] = $cells;
        }
        $book->disconnectWorksheets();

        return $out;
    }

    /** @return array<int, list<string|null>> */
    public static function csv(string $raw): array
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (! mb_check_encoding($raw, 'UTF-8')) {
            // Excel в русской Windows сохраняет CSV в cp1251
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
        }
        $firstLine = strtok($raw, "\r\n") ?: '';
        $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        if (substr_count($firstLine, "\t") > max(substr_count($firstLine, ';'), substr_count($firstLine, ','))) {
            $delimiter = "\t";
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $raw);
        rewind($stream);
        $out = [];
        $n = 0;
        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $n++;
            if ($n > self::MAX_ROWS * 3 + 10) {
                fclose($stream);
                throw new RowError(t('import.errors.tooManyRows', ['max' => self::MAX_ROWS]));
            }
            $out[$n] = array_map(fn ($v) => $v === null ? null : (string) $v, array_slice($row, 0, 60));
        }
        fclose($stream);

        return $out;
    }
}
