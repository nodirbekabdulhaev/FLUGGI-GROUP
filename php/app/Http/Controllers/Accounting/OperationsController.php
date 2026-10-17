<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccCategory;
use App\Models\AccPerson;
use App\Services\Accounting\Accounting;
use App\Services\Accounting\AccountingExport;
use App\Services\Accounting\AccountsService;
use App\Services\Accounting\CategoriesService;
use App\Services\Accounting\CrmImportService;
use App\Services\Accounting\OperationsService;
use App\Services\Finance\Input;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Операции по счетам: список с фильтрами, приход/расход/перевод, удаление с причиной, загрузка оплат CRM, выгрузка. */
class OperationsController extends Controller
{
    /** Базовая категория или id своей статьи (статья проверяется в сервисе). */
    private function categoryRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (! is_string($value) || (! in_array($value, Accounting::categories(), true) && ! Str::isUuid($value))) {
                $fail(t('accounting.errors.category'));
            }
        };
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'account' => ['nullable', 'uuid'],
            'direction' => ['nullable', Rule::in(['IN', 'OUT'])],
            'category' => ['nullable', $this->categoryRule()],
            'period' => ['nullable', 'regex:'.Accounting::PERIOD_RE],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'person' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
    }

    public function index(Request $request): View
    {
        $f = $this->filters($request);
        $q = OperationsService::query($f);
        // «Оплатить» налог / «Выплатить» зарплату: форма открывается заполненной
        $prefill = $request->validate([
            'new' => ['nullable', Rule::in(['IN', 'OUT', 'TRANSFER'])],
            'p_category' => ['nullable', Rule::in(Accounting::categories())],
            'p_period' => ['nullable', 'regex:'.Accounting::PERIOD_RE],
            'p_amount' => ['nullable', Input::MONEY],
            'p_account' => ['nullable', 'uuid'],
            'p_person' => ['nullable', 'uuid'],
        ]);

        return view('accounting.operations', [
            'filters' => $f,
            'items' => (clone $q)->paginate(50)->withQueryString(),
            'totals' => OperationsService::totals($q),
            'accounts' => AccountsService::list(),
            'articles' => ['IN' => CategoriesService::options('IN'), 'OUT' => CategoriesService::options('OUT')],
            'filterArticles' => AccCategory::orderBy('direction')->orderBy('sort')->orderBy('name')->get(),
            'people' => AccPerson::orderByDesc('is_active')->orderBy('full_name')->get(['id', 'full_name', 'is_active']),
            'prefill' => $prefill,
            'canManage' => access()->can('accounting.manage'),
            'canExport' => access()->can('export.run'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Input::normalize($request, ['amount']);
        $data = $request->validate([
            'account_id' => ['required', 'uuid'],
            'direction' => ['required', Rule::in(['IN', 'OUT'])],
            'category' => ['required', $this->categoryRule()],
            'amount' => ['required', Input::MONEY, 'not_regex:/^0+(\.0+)?$/'],
            'date' => ['required', 'date_format:Y-m-d'],
            'period' => ['nullable', 'regex:'.Accounting::PERIOD_RE],
            'counterparty' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:2000'],
            'person_id' => ['nullable', 'uuid'],
        ]);
        OperationsService::create($data);

        return back()->with('ok', t('accounting.operations.created'));
    }

    public function transfer(Request $request): RedirectResponse
    {
        Input::normalize($request, ['amount', 'amount_to']);
        $data = $request->validate([
            'from_id' => ['required', 'uuid'],
            'to_id' => ['required', 'uuid'],
            'amount' => ['required', Input::MONEY, 'not_regex:/^0+(\.0+)?$/'],
            'amount_to' => ['nullable', Input::MONEY, 'not_regex:/^0+(\.0+)?$/'],
            'date' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        OperationsService::transfer($data);

        return back()->with('ok', t('accounting.operations.transferred'));
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']])['reason'];
        OperationsService::delete($id, trim($reason));

        return back()->with('ok', t('accounting.operations.deleted'));
    }

    public function importCrm(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $c = CrmImportService::importPeriod($d['from'], $d['to']);

        return back()->with('ok', t('accounting.import.done', ['imported' => $c['imported'], 'exists' => $c['exists'], 'skipped' => $c['noAccount'] + $c['beforeOpening'] + $c['noRate']]));
    }

    public function export(Request $request): Response
    {
        $f = AccountingExport::operations($this->filters($request));

        return response($f['body'], 200, ['Content-Type' => AccountingExport::XLSX, 'Content-Disposition' => 'attachment; filename="'.$f['filename'].'"']);
    }
}
