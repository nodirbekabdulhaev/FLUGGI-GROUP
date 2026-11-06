<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccPayroll;
use App\Services\Accounting\Accounting;
use App\Services\Accounting\TaxesService;
use App\Services\Accounting\TaxPayrollService;
use App\Services\Finance\Input;
use App\Services\Finance\Period;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Зарплата для налоговой за месяц: расчёт черновиков, правка начисленного, утверждение. */
class PayrollController extends Controller
{
    public function index(Request $request): View
    {
        $period = Period::month($request->query('period'));
        $canManage = access()->can('accounting.manage');
        $editing = $canManage && ($id = $request->query('edit')) ? AccPayroll::with('person')->where('status', 'DRAFT')->findOrFail($id) : null;

        return view('accounting.payroll', ['period' => $period, 'settings' => Accounting::settings(), 'canManage' => $canManage,
            'editing' => $editing, 'payAccount' => TaxesService::payAccount()] + TaxPayrollService::sheet($period));
    }

    private function period(Request $request): string
    {
        return $request->validate(['period' => ['required', 'regex:'.Accounting::PERIOD_RE]])['period'];
    }

    public function calculate(Request $request): RedirectResponse
    {
        $period = $this->period($request);
        $r = TaxPayrollService::calculate($period);

        return redirect('/accounting/payroll?period='.$period)->with('ok', t('accounting.payroll.calculated', ['created' => $r['created'], 'updated' => $r['updated']]));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        Input::normalize($request, ['gross']);
        $gross = $request->validate(['gross' => ['required', Input::MONEY]])['gross'];
        $row = TaxPayrollService::updateGross($id, $gross);

        return redirect('/accounting/payroll?period='.$row->period)->with('ok', t('accounting.payroll.saved'));
    }

    public function approve(Request $request): RedirectResponse
    {
        $period = $this->period($request);
        $ids = $request->validate(['ids' => ['nullable', 'array', 'max:500'], 'ids.*' => ['uuid']])['ids'] ?? null;
        $n = TaxPayrollService::approve($period, $ids ?: null);

        return redirect('/accounting/payroll?period='.$period)->with('ok', t('accounting.payroll.approved', ['count' => $n]));
    }
}
