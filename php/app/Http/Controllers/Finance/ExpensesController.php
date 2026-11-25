<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\User;
use App\Services\Finance\CatalogService;
use App\Services\Finance\ExpenseService;
use App\Services\Finance\Input;
use App\Services\Projects\ProjectAccess;
use App\Services\Projects\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Расходы (ТЗ §26): проектные и компании; правка и мягкое удаление с аудитом. */
class ExpensesController extends Controller
{
    public function index(Request $request): View
    {
        $f = $request->validate([
            'scope' => ['nullable', Rule::in(['PROJECT', 'COMPANY'])],
            'category' => ['nullable', 'string', 'max:40'],
            'edit' => ['nullable', 'uuid'],
        ]);
        $list = ExpenseService::list($f);
        $editing = ! empty($f['edit']) ? ExpenseService::query('expense.update')->whereKey($f['edit'])->with('project:id,number,name')->first() : null;

        return view('finance.expenses', [
            'filters' => $f,
            'expenses' => $list['page'],
            'totalUzs' => $list['totalUzs'],
            'categories' => CatalogService::categoryOptions('EXPENSE', $f['category'] ?? null),
            'editing' => $editing,
        ] + self::formOptions($editing));
    }

    /** Списки для формы расхода: проекты и получатели. */
    public static function formOptions(?Expense $editing = null, ?string $projectId = null): array
    {
        $access = access();
        // Получатель: CEO выбирает из всех сотрудников, остальные — из команды проекта
        $payees = [];
        if ($access->can('employee.read', 'ALL')) {
            $payees = User::where('status', 'ACTIVE')->orderBy('full_name')->pluck('full_name', 'id')->all();
        } elseif ($pid = $editing?->project_id ?? $projectId) {
            $p = ProjectAccess::projects()->with(['rop:id,full_name', 'manager:id,full_name', 'members.user:id,full_name'])->find($pid);
            if ($p) {
                $payees = [$p->rop_id => $p->rop->full_name, $p->manager_id => $p->manager->full_name] + $p->members->pluck('user.full_name', 'user_id')->all();
            }
        }
        if ($editing?->payee_user_id && ! isset($payees[$editing->payee_user_id])) {
            $payees[$editing->payee_user_id] = User::withTrashed()->find($editing->payee_user_id)?->full_name;
        }

        return [
            'projectOptions' => $access->can('expense.create') ? ProjectService::options('expense.create') : [],
            'payees' => $payees,
            'expenseCategories' => CatalogService::categoryOptions('EXPENSE', $editing?->category),
            'company' => ExpenseService::companyAllowed(),
        ];
    }

    private function rules(bool $create): array
    {
        return [
            'scope' => [$create ? 'required' : 'prohibited', Rule::in(['PROJECT', 'COMPANY'])],
            'project_id' => [$create ? 'nullable' : 'prohibited', 'uuid', $create ? 'required_if:scope,PROJECT' : 'nullable', $create ? 'prohibited_if:scope,COMPANY' : 'nullable'],
            'category' => [$create ? 'required' : 'sometimes', 'string', 'max:40'],
            'amount' => [$create ? 'required' : 'sometimes', Input::MONEY, 'not_in:0,0.0,0.00'],
            'currency' => [$create ? 'required' : 'sometimes', Rule::in(['UZS', 'USD'])],
            'expense_date' => [$create ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'payee_user_id' => ['sometimes', 'nullable', 'uuid'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        Input::normalize($request, ['amount']);
        $data = $request->validate($this->rules(true), ['project_id.prohibited_if' => t('finance.errors.scopeProject'), 'project_id.required_if' => t('finance.errors.scopeProject')]);
        ExpenseService::create($data + ['project_id' => null, 'payee_user_id' => null, 'description' => null]);

        return back()->with('ok', t('finance.expenses.created'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        Input::normalize($request, ['amount']);
        ExpenseService::update($id, collect($request->validate($this->rules(false)))->except('return_to')->all());

        $back = (string) $request->input('return_to');

        // Возврат на страницу, откуда открыли форму (список расходов или финансы проекта)
        return redirect(str_starts_with($back, '/') && ! str_starts_with($back, '//') ? $back : '/finance/expenses')->with('ok', t('finance.expenses.saved'));
    }

    public function destroy(string $id): RedirectResponse
    {
        ExpenseService::remove($id);

        return back()->with('ok', t('finance.expenses.deleted'));
    }
}
