<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingExport;
use App\Services\Accounting\TaxesService;
use App\Services\Finance\Period;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** Налоги месяца: НДФЛ, ИНПС, IT Park — к уплате, оплачено, остаток; отчёт в Excel. */
class TaxesController extends Controller
{
    public function index(Request $request): View
    {
        $period = Period::month($request->query('period'));

        return view('accounting.taxes', TaxesService::forPeriod($period) + [
            'payAccount' => TaxesService::payAccount(),
            'canManage' => access()->can('accounting.manage'),
            'canExport' => access()->can('export.run'),
        ]);
    }

    public function export(Request $request): Response
    {
        $f = AccountingExport::taxReport(Period::month($request->query('period')));

        return response($f['body'], 200, ['Content-Type' => AccountingExport::XLSX, 'Content-Disposition' => 'attachment; filename="'.$f['filename'].'"']);
    }
}
