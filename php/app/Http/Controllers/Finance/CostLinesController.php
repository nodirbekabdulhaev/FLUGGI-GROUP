<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\CostLineService;
use App\Services\Finance\Input;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** План расходов по тарифу (вкладка «Финансы» проекта): назначить, поправить, начислить, убрать. */
class CostLinesController extends Controller
{
    public function update(Request $request, string $id): RedirectResponse
    {
        Input::normalize($request, ['rate']);
        $data = $request->validate([
            'quantity' => ['sometimes', 'numeric', 'gt:0', 'max:100000'],
            'rate' => ['sometimes', Input::MONEY],
            'assignee_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
        CostLineService::update($id, $data);

        return back()->with('ok', t('costLines.saved'));
    }

    public function accrue(string $id): RedirectResponse
    {
        CostLineService::accrue($id);

        return back()->with('ok', t('costLines.accrued'));
    }

    public function cancel(string $id): RedirectResponse
    {
        CostLineService::cancel($id);

        return back()->with('ok', t('costLines.saved'));
    }
}
