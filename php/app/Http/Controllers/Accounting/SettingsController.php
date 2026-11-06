<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\Accounting;
use App\Services\Finance\Input;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Ставки бухгалтерии: НДФЛ, ИНПС (в составе НДФЛ или сверху), отчисление IT Park. */
class SettingsController extends Controller
{
    public function index(): View
    {
        return view('accounting.settings', ['settings' => Accounting::settings()]);
    }

    public function update(Request $request): RedirectResponse
    {
        Input::normalize($request, ['ndflRate', 'inpsRate', 'itparkRate']);
        $rate = ['required', 'regex:/^\d{1,2}(\.\d{1,3})?$/'];
        $data = $request->validate(['ndflRate' => $rate, 'inpsRate' => $rate, 'itparkRate' => $rate]);
        $data['inpsWithinNdfl'] = $request->boolean('inpsWithinNdfl');
        Accounting::saveSettings($data);

        return back()->with('ok', t('accounting.settings.saved'));
    }
}
