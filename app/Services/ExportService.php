<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV (UTF-8 with BOM, ';' separator → opens in Excel as-is) and PDF exports. */
class ExportService
{
    public function csv(string $name, array $headers, iterable|Collection $rows): StreamedResponse
    {
        $file = $name.'_'.now()->format('Y-m-d_Hi').'.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ';');
            foreach ($rows as $row) {
                // neutralise spreadsheet formula injection
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, (array) $row), ';');
            }
            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function pdf(string $name, string $title, array $headers, iterable|Collection $rows, array $summary = [])
    {
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.table', [
            'title' => $title, 'headers' => $headers, 'rows' => collect($rows), 'summary' => $summary,
        ])->setPaper('a4', count($headers) > 6 ? 'landscape' : 'portrait');

        return $pdf->download($name.'_'.now()->format('Y-m-d').'.pdf');
    }
}
