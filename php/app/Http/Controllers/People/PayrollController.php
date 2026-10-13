<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Models\PayrollEntry;
use App\Services\Finance\Input;
use App\Services\Finance\Period;
use App\Services\People\PeopleService;
use App\Services\Smm\SmmPayroll;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Зарплата (ТЗ §32): свою видит каждый, всех — только CEO; утверждённые строки неизменяемы. */
class PayrollController extends Controller
{
    public function index(Request $request): View
    {
        $period = Period::month($request->query('period'));
        $manage = access()->can('payroll.manage', 'ALL');
        $data = ['period' => $period, 'manage' => $manage, 'entries' => PeopleService::payroll($period)];
        if ($manage) {
            $data['salaries'] = PeopleService::salaries();
            $data['videoPlans'] = SmmPayroll::plans();
        }
        if ($manage && ($id = $request->query('edit'))) {
            $data['editing'] = PayrollEntry::with(['user.role', 'user.employee'])->findOrFail($id);
        }

        return view('people.payroll', $data);
    }

    public function calculate(Request $request): RedirectResponse
    {
        $period = $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']])['period'];
        PeopleService::calculate($period);

        return redirect('/finance/payroll?period='.$period)->with('ok', t('payroll.calculated'));
    }

    /** Оклады менеджеров и РОП при 100 % посещаемости. */
    public function salaries(Request $request): RedirectResponse
    {
        $raw = (array) $request->input('salaries', []);
        $request->merge(['salaries' => array_map(fn ($v) => Input::money($v), $raw)]);
        $data = $request->validate([
            'salaries' => ['required', 'array', 'max:500'],
            'salaries.*' => ['nullable', Input::MONEY],
            'period' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);
        PeopleService::saveSalaries($data['salaries']);

        return redirect('/finance/payroll?period='.($data['period'] ?? Period::month(null)))->with('ok', t('payroll.salariesSaved'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        Input::normalize($request, ['base_salary', 'kpi_bonus', 'other_bonus', 'penalty', 'kpi_bonus_target']);
        $data = $request->validate([
            'base_salary' => ['sometimes', 'nullable', Input::MONEY],
            'kpi_bonus' => ['sometimes', 'nullable', Input::MONEY],
            'other_bonus' => ['sometimes', 'nullable', Input::MONEY],
            'penalty' => ['sometimes', 'nullable', Input::MONEY],
            'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'kpi_bonus_target' => ['sometimes', 'nullable', Input::MONEY],
        ]);
        foreach (['base_salary', 'kpi_bonus', 'other_bonus', 'penalty'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] === null) {
                $data[$k] = '0';
            }
        }
        // Изменили «бонус при 100%», а сумму KPI-бонуса не трогали — пересчитать её по выполнению KPI
        $entry = PayrollEntry::with('user.employee')->findOrFail($id);
        $oldTarget = $entry->user->employee?->kpi_bonus_target;
        $same = fn ($a, $b) => $a !== null && $b !== null ? BigDecimal::of((string) $a)->isEqualTo((string) $b) : $a === $b;
        if (array_key_exists('kpi_bonus_target', $data) && ! $same($oldTarget, $data['kpi_bonus_target'])
            && isset($data['kpi_bonus']) && $same($entry->kpi_bonus, $data['kpi_bonus'])) {
            unset($data['kpi_bonus']);
        }
        $entry = PeopleService::updatePayroll($id, $data);

        return redirect('/finance/payroll?period='.$entry->period)->with('ok', t('payroll.saved'));
    }

    public function approve(Request $request): RedirectResponse
    {
        $n = PeopleService::transition($this->ids($request), 'APPROVED');

        return back()->with('ok', t('payroll.approved', ['count' => $n]));
    }

    public function pay(Request $request): RedirectResponse
    {
        $n = PeopleService::transition($this->ids($request), 'PAID');

        return back()->with('ok', t('payroll.paid', ['count' => $n]));
    }

    private function ids(Request $request): array
    {
        return $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['uuid']])['ids'];
    }
}
