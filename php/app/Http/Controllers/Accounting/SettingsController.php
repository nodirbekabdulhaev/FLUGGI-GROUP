<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\Accounting;
use App\Services\Finance\Input;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Ставки бухгалтерии: НДФЛ и льгота компании, ИНПС (в составе НДФЛ или сверху), отчисление IT Park; имя файла отчёта в налоговую. */
class SettingsController extends Controller
{
    public function index(): View
    {
        return view('accounting.settings', ['settings' => Accounting::settings(), 'companyInn' => (string) (Settings::company()['inn'] ?? '')]);
    }

    public function update(Request $request): RedirectResponse
    {
        Input::normalize($request, ['ndflBaseRate', 'benefitPercent', 'inpsRate', 'itparkRate']);
        $rate = ['sometimes', 'required', 'regex:/^\d{1,2}(\.\d{1,3})?$/'];
        $data = $request->validate([
            'ndflBaseRate' => $rate, 'inpsRate' => $rate, 'itparkRate' => $rate,
            'benefitCode' => ['sometimes', 'nullable', 'regex:/^\d{1,10}$/'],
            'benefitPercent' => ['sometimes', 'required', 'regex:/^(100|\d{1,2}(\.\d{1,3})?)$/'],
            'companyInn' => ['sometimes', 'nullable', 'regex:/^\d{9}$/'],
            'taxReportCode' => ['sometimes', 'nullable', 'regex:/^[A-Za-z0-9]{1,30}$/'],
            'taxReportFileName' => ['sometimes', 'nullable', 'max:191', 'regex:/^[A-Za-z0-9_.{}\-]+$/'],
        ]);
        $data['inpsWithinNdfl'] = $request->boolean('inpsWithinNdfl');
        Accounting::saveSettings($data);

        return back()->with('ok', t('accounting.settings.saved'));
    }
}
