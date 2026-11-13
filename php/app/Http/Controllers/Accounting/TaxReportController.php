<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\Accounting;
use App\Services\Accounting\AccountingExport;
use App\Services\Accounting\TaxReport;
use App\Services\Accounting\TaxReportExport;
use App\Services\Finance\Period;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** Отчёт в налоговую за месяц (приложение 4 к расчёту НДФЛ): просмотр и файл по официальному шаблону. */
class TaxReportController extends Controller
{
    public function index(Request $request): View
    {
        $period = self::period($request);

        return view('accounting.tax-report', TaxReport::build($period) + [
            'canDownload' => access()->can('accounting.manage') && access()->can('export.run'),
            'canManage' => access()->can('accounting.manage'),
        ]);
    }

    public function download(Request $request): Response
    {
        $f = TaxReportExport::build(self::period($request));

        return response($f['body'], 200, ['Content-Type' => AccountingExport::XLSX, 'Content-Disposition' => 'attachment; filename="'.$f['filename'].'"']);
    }

    /** Месяц из запроса; по умолчанию — прошлый (отчёт сдаётся после окончания месяца). */
    private static function period(Request $request): string
    {
        $v = $request->query('period');

        return is_string($v) && preg_match(Accounting::PERIOD_RE, $v) ? $v
            : CarbonImmutable::createFromFormat('!Y-m', Period::currentMonth())->subMonthNoOverflow()->format('Y-m');
    }
}
