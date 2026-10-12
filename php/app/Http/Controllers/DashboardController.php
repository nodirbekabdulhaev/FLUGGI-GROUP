<?php

namespace App\Http\Controllers;

use App\Services\Finance\Period;
use App\Services\People\DashboardService;
use Illuminate\View\View;

/** Главная: CEO — показатели компании, РОП — отдела, остальные — личные (ТЗ §5, §58–60). */
class DashboardController extends Controller
{
    public function show(): View
    {
        $period = Period::fromRequest();

        return view('dashboard.show', ['access' => access(), 'd' => DashboardService::get($period), 'period' => $period]);
    }
}
