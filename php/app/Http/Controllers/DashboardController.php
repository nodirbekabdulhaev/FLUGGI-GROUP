<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\AgendaService;
use App\Services\Dashboard\ChartsService;
use App\Services\Dashboard\WalletsService;
use App\Services\Finance\Period;
use App\Services\People\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Главная: CEO — показатели компании, РОП — отдела, остальные — личные (ТЗ §5, §58–60); «Мой день», счета, графики. */
class DashboardController extends Controller
{
    public function show(): View
    {
        $period = Period::fromRequest();
        $access = access();

        return view('dashboard.show', [
            'access' => $access,
            'd' => DashboardService::get($period),
            'period' => $period,
            'agenda' => AgendaService::get($access, 'day'),
            'wallets' => WalletsService::get($access),
            'charts' => ChartsService::get($access, $period),
        ]);
    }

    /** «Мой день»: ?range=day|week|month|year&date=YYYY-MM-DD&who=me|all — всё в области видимости пользователя. */
    public function agenda(Request $request): JsonResponse
    {
        $q = fn (string $k) => is_string($v = $request->query($k)) ? $v : null;

        return response()->json(AgendaService::get(access(), $q('range'), $q('date'), $q('who')));
    }
}
