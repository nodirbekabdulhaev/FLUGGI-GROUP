<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/** Главная: CEO — показатели компании, РОП — отдела, остальные — личные (ТЗ §5). */
class DashboardController extends Controller
{
    public function show(): View
    {
        return view('dashboard.show', ['access' => access()]);
    }
}
