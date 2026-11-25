<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Services\Finance\Input;
use App\Services\People\AdvanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Авансы сотрудникам: выдача и отмена (CEO, payroll.manage на всю компанию). */
class AdvancesController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        Input::normalize($request, ['amount']);
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'amount' => ['required', Input::MONEY],
            'paid_at' => ['required', 'date_format:Y-m-d'],
            'period' => ['nullable', 'regex:'.AdvanceService::PERIOD_RE],
            'comment' => ['nullable', 'string', 'max:1000'],
            'accounting' => ['nullable', 'boolean'],
            'account_id' => ['nullable', 'uuid'],
        ]);
        $a = AdvanceService::create($data);

        return back()->with('ok', t('payroll.advance.created', ['amount' => money($a->amount), 'period' => $a->period]));
    }

    public function cancel(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        AdvanceService::cancel($id, $data['reason']);

        return back()->with('ok', t('payroll.advance.cancelledOk'));
    }
}
