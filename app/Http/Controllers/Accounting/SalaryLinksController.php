<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\SalaryLinks;
use App\Services\Finance\Period;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Привязка выплат зарплаты из выписок к месяцам и людям по назначению платежа и получателю. */
class SalaryLinksController extends Controller
{
    private function year(Request $request): int
    {
        $y = (int) $request->input('year', substr(Period::currentMonth(), 0, 4));

        return $y >= 2000 && $y <= 2100 ? $y : (int) substr(Period::currentMonth(), 0, 4);
    }

    public function index(Request $request): View
    {
        $year = $this->year($request);

        return view('accounting.salary-links', ['year' => $year, 'plan' => SalaryLinks::plan($year)]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $year = $this->year($request);
        $ids = $request->validate(['ids' => ['nullable', 'array', 'max:2000'], 'ids.*' => ['uuid']])['ids'] ?? [];
        $r = SalaryLinks::apply($year, $ids);

        return redirect('/accounting/payroll?period='.Period::currentMonth())->with('ok', t('accounting.salaryLinks.done', ['operations' => $r['operations'], 'year' => $year]));
    }
}
