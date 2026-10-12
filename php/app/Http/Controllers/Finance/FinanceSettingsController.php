<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\CatalogService;
use App\Services\Finance\OverheadService;
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
}
