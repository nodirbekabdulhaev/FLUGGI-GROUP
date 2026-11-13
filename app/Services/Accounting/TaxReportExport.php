<?php

namespace App\Services\Accounting;

use App\Support\Audit;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Файл отчёта в налоговую из официального шаблона (resources/templates/tax/ndfl-ilova-4-5.xlsx):
 * шапка, объединения и итоги (=SUM(L15:L1048576) в строке 12) сохраняются, данные — с 15-й строки листа list01.
 * Лист list02 (приложение 5: имущественные доходы, материальная выгода) остаётся пустым — таких доходов бухгалтерия не ведёт.
 * ПИНФЛ, ФИО, должность и дата — текстом (ПИНФЛ без экспоненты), коды и суммы — числами, как в образце.
 */
final class TaxReportExport
{
    /** @return array{body:string,filename:string,rows:int} */
    public static function build(string $period): array
    {
        $report = TaxReport::build($period);
        $book = IOFactory::load(resource_path(TaxReport::TEMPLATE));
        $book->getProperties()->setCreator('Fluggi CRM')->setLastModifiedBy('Fluggi CRM')
            ->setTitle('4-илова '.$report['year'].'-'.sprintf('%02d', $report['month']))->setCompany('')->setManager('');
        $ws = $book->getSheetByName('list01');

        // В шапке год написан в тексте («01.01.2026») — подставляем год отчёта
        $year = fn (string $text) => (string) preg_replace('/01\.01\.\d{4}/', '01.01.'.$report['year'], $text);
        foreach (['F10', 'K10'] as $c) {
            $v = $ws->getCell($c)->getValue();
            if ($v instanceof RichText) {
                foreach ($v->getRichTextElements() as $run) {
                    $run->setText($year($run->getText()));
                }
            } elseif (is_string($v)) {
                $ws->getCell($c)->setValueExplicit($year($v), DataType::TYPE_STRING);
            }
        }

        $num = fn (string $v) => ($d = BigDecimal::of($v))->hasNonZeroFractionalPart() ? (float) $v : $d->toScale(0)->toInt();
        $str = fn (string $col, int $r, string $v) => $v === '' ? null : $ws->getCell($col.$r)->setValueExplicit($v, DataType::TYPE_STRING);
        $set = fn (string $col, int $r, int|float $v) => $ws->getCell($col.$r)->setValueExplicit($v, DataType::TYPE_NUMERIC);
        foreach ($report['rows'] as $i => $row) {
            $r = TaxReport::FIRST_ROW + $i;
            $set('B', $r, $i + 1);
            $str('C', $r, $row['name']);
            $str('D', $r, $row['position']);
            $str('E', $r, $row['pinfl']);
            $str('F', $r, $row['workplaceDate']);
            $set('G', $r, $row['resident']);
            $set('H', $r, $row['status']);
            $set('I', $r, $row['contract']);
            $set('J', $r, $num($row['rate']));
            $set('K', $r, $row['workplaceType']);
            $set('L', $r, $num($row['ytdIncome']));
            $set('M', $r, $num($row['monthIncome']));
            $set('N', $r, $num($row['ytdNdfl']));
            $set('O', $r, $num($row['monthNdfl']));
            foreach ($row['benefits'] as $k => $b) {
                $col = 16 + 3 * $k; // P, S, V … AQ
                ctype_digit($b['code']) ? $set(Coordinate::stringFromColumnIndex($col), $r, (int) $b['code']) : $str(Coordinate::stringFromColumnIndex($col), $r, $b['code']);
                $set(Coordinate::stringFromColumnIndex($col + 1), $r, $num($b['ytd']));
                $set(Coordinate::stringFromColumnIndex($col + 2), $r, $num($b['month']));
            }
        }
        $book->setActiveSheetIndex($book->getIndex($ws));

        $writer = new Xlsx($book);
        // Итоги считаются в Excel при открытии (SUM до строки 1048576 — пересчитывать здесь незачем)
        $writer->setPreCalculateFormulas(false);
        ob_start();
        $writer->save('php://output');
        $body = (string) ob_get_clean();
        $book->disconnectWorksheets();

        DB::transaction(fn () => Audit::log('export.run', 'acc_tax_form', null, ['export' => ['old' => null, 'new' => [
            'period' => $period, 'rows' => count($report['rows']), 'filename' => $report['filename'],
        ]]]));

        return ['body' => $body, 'filename' => $report['filename'], 'rows' => count($report['rows'])];
    }
}
