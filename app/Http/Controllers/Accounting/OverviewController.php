<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\OverviewService;
use App\Services\Finance\Period;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Обзор бухгалтерии: остатки счетов, итоги месяца, налоги, последние операции. */
class OverviewController extends Controller
{
    public function index(Request $request): View
    {
        $period = Period::month($request->query('period'));

        return view('accounting.overview', ['period' => $period] + OverviewService::month($period));
    }
}
