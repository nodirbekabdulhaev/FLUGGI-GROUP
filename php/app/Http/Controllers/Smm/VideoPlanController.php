<?php

namespace App\Http\Controllers\Smm;

use App\Http\Controllers\Controller;
use App\Services\Finance\Input;
use App\Services\Finance\Period;
use App\Services\Smm\SmmPayroll;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Блок «SMM: оклад и план роликов» на странице зарплаты (только payroll.manage на всю компанию). */
class VideoPlanController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $raw = (array) $request->input('plans', []);
        $request->merge(['plans' => array_map(fn ($p) => [
            'salary' => isset($p['salary']) ? str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string) $p['salary']) : null,
            'target' => isset($p['target']) ? trim((string) $p['target']) : null,
        ], array_filter($raw, 'is_array'))]);
        $data = $request->validate([
            'plans' => ['required', 'array', 'max:500'],
            'plans.*.salary' => ['nullable', Input::MONEY],
            'plans.*.target' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'period' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ], [], ['plans.*.salary' => t('payroll.smmSalary'), 'plans.*.target' => t('payroll.smmTarget')]);
        SmmPayroll::save($data['plans']);

        return redirect('/finance/payroll?period='.($data['period'] ?? Period::month(null)))->with('ok', t('payroll.smmSaved'));
    }
}
