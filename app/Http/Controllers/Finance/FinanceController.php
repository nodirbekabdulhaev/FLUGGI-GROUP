<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceService;
use App\Services\Finance\Period;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Финансовый обзор (ТЗ §27) и прибыль по проектам (ТЗ §25). */
class FinanceController extends Controller
{
    public function revenue(): View
    {
        $period = Period::fromRequest();

        return view('finance.revenue', ['period' => $period, 's' => FinanceService::summary($period)]);
    }

    public function profit(Request $request): View
    {
        $q = $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? null;

        return view('finance.profit', ['q' => $q, 'projects' => FinanceService::projectsProfit($q)]);
    }
}
