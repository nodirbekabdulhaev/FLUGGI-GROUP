<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\CatalogService;
use App\Services\Finance\Input;
use App\Services\Finance\OverheadService;
use App\Services\Projects\ProjectAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Настройки → «Финансы»: категории доходов и расходов, накладные, единицы работ исполнителей. */
class FinanceSettingsController extends Controller
{
    public function index(): View
    {
        return view('settings.finance', [
            'expenseCategories' => CatalogService::categories('EXPENSE'),
            'incomeCategories' => CatalogService::categories('INCOME'),
            'overhead' => OverheadService::settings(),
            'workItems' => CatalogService::workItems(),
        ]);
    }

    private function category(Request $request): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['EXPENSE', 'INCOME'])],
            'name' => ['required', 'string', 'max:80'],
            'account_hint' => ['nullable', 'string', 'max:20'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        return $data + ['account_hint' => null, 'sort' => (int) ($data['sort'] ?? 100), 'is_overhead' => $request->boolean('is_overhead'), 'is_active' => $request->boolean('is_active')];
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        CatalogService::saveCategory(null, $this->category($request));

        return back()->with('ok', t('financeSettings.saved'));
    }

    public function updateCategory(Request $request, string $id): RedirectResponse
    {
        CatalogService::saveCategory($id, $this->category($request));

        return back()->with('ok', t('financeSettings.saved'));
    }

    public function destroyCategory(string $id): RedirectResponse
    {
        CatalogService::deleteCategory($id);

        return back()->with('ok', t('financeSettings.saved'));
    }

    public function overhead(Request $request): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(['auto', 'fixed'])], 'divisor' => ['required_if:mode,fixed', 'nullable', 'integer', 'min:1', 'max:1000']]);
        OverheadService::saveSettings($data['mode'] === 'fixed' ? (int) $data['divisor'] : null);

        return back()->with('ok', t('financeSettings.saved'));
    }

    private function workItem(Request $request): array
    {
        Input::normalize($request, ['default_rate']);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'unit' => ['required', 'string', 'max:20'],
            'specialty' => ['nullable', Rule::in(ProjectAccess::SPECIALTIES)],
            'default_rate' => ['required', Input::MONEY],
            'currency' => ['required', Rule::in(['UZS', 'USD'])],
        ]);

        return $data + ['specialty' => null, 'is_active' => $request->boolean('is_active')];
    }

    public function storeWorkItem(Request $request): RedirectResponse
    {
        CatalogService::saveWorkItem(null, $this->workItem($request));

        return back()->with('ok', t('financeSettings.saved'));
    }

    public function updateWorkItem(Request $request, string $id): RedirectResponse
    {
        CatalogService::saveWorkItem($id, $this->workItem($request));

        return back()->with('ok', t('financeSettings.saved'));
    }
}
