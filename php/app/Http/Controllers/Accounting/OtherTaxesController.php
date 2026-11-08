<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\OtherTaxes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Проверка «Прочих налогов»: получатели платежей и перенос в верные статьи. */
class OtherTaxesController extends Controller
{
    public function index(): View
    {
        return view('accounting.other-taxes', ['groups' => OtherTaxes::groups(), 'targets' => OtherTaxes::targets()]);
    }

    public function apply(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'max:500'],
            'rows.*.cp' => ['nullable', 'string', 'max:191'],
            'rows.*.category' => ['nullable', Rule::in(OtherTaxes::targets())],
        ]);
        $r = OtherTaxes::apply($data['rows']);

        return redirect('/accounting/other-taxes')->with('ok', t('accounting.otherTaxes.done', ['groups' => $r['groups'], 'operations' => $r['operations']]));
    }
}
