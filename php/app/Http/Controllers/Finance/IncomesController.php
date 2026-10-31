<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\OtherIncome;
use App\Services\Finance\CatalogService;
use App\Services\Finance\Input;
use App\Services\Projects\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Прочие поступления — доходы не от клиентов (только CEO: finance.company.read на всю компанию). */
class IncomesController extends Controller
{
    public function index(Request $request): View
    {
        $f = $request->validate(['category' => ['nullable', 'string', 'max:40'], 'edit' => ['nullable', 'uuid']]);
        $editing = ! empty($f['edit']) ? OtherIncome::find($f['edit']) : null;

        return view('finance.incomes', [
            'filters' => $f,
            'incomes' => CatalogService::incomes($f),
            'categories' => CatalogService::categoryOptions('INCOME', $editing?->category),
            'allCategories' => CatalogService::categories('INCOME')->pluck('name', 'code')->all(),
            'projects' => ProjectService::options('project.read'),
            'editing' => $editing,
        ]);
    }

    private function input(Request $request): array
    {
        Input::normalize($request, ['amount']);
        $data = $request->validate([
            'category' => ['required', 'string', 'max:40'],
            'amount' => ['required', Input::MONEY, 'not_in:0,0.0,0.00'],
            'currency' => ['required', Rule::in(['UZS', 'USD'])],
            'income_date' => ['required', 'date_format:Y-m-d'],
            'project_id' => ['nullable', 'uuid', 'exists:projects,id'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        return $data + ['project_id' => null, 'client_id' => null, 'description' => null];
    }

    public function store(Request $request): RedirectResponse
    {
        CatalogService::saveIncome(null, $this->input($request));

        return back()->with('ok', t('incomes.saved'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        CatalogService::saveIncome($id, $this->input($request));

        return redirect('/finance/incomes')->with('ok', t('incomes.saved'));
    }

    public function destroy(string $id): RedirectResponse
    {
        CatalogService::deleteIncome($id);

        return back()->with('ok', t('incomes.deleted'));
    }
}
