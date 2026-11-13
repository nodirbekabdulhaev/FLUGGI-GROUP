<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccPerson;
use App\Models\User;
use App\Services\Accounting\Accounting;
use App\Services\Accounting\TaxPayrollService;
use App\Services\Accounting\TaxReport;
use App\Services\Finance\Input;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Люди, оформленные для налоговой (не обязательно сотрудники CRM): данные для отчёта в налоговую, история оклада, льготы. */
class PeopleController extends Controller
{
    public function index(Request $request): View
    {
        $canManage = access()->can('accounting.manage');

        return view('accounting.people', [
            'people' => TaxPayrollService::people(),
            'users' => $canManage ? User::where('status', 'ACTIVE')->orderBy('full_name')->pluck('full_name', 'id')->all() : [],
            'canManage' => $canManage,
            'editing' => $canManage && ($id = $request->query('edit')) ? AccPerson::with('salaries')->findOrFail($id) : null,
            'settings' => Accounting::settings(),
        ]);
    }

    private function input(Request $request): array
    {
        Input::normalize($request, ['monthly_salary']);
        $request->merge([
            'pinfl' => preg_replace('/\s+/', '', (string) $request->input('pinfl')) ?: null,
            'work_rate' => Input::money($request->input('work_rate')) ?? '1',
        ]);
        $rows = $request->input('benefits_mode') !== 'custom' ? [] : collect((array) $request->input('benefits', []))->filter(fn ($b) => is_array($b) && trim((string) ($b['code'] ?? '')) !== '')
            ->map(fn ($b) => ['code' => trim((string) $b['code']), 'kind' => $b['kind'] ?? 'percent', 'value' => Input::money($b['value'] ?? null)])->values()->all();
        $request->merge(['benefits' => $rows]);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:191'],
            'passport_name' => ['nullable', 'string', 'max:191'],
            'pinfl' => ['nullable', 'regex:/^\d{14}$/'],
            'position' => ['nullable', 'string', 'max:191'],
            'resident_type' => ['nullable', 'integer', 'in:'.implode(',', TaxReport::RESIDENT_TYPES)],
            'contract_type' => ['nullable', 'integer', 'in:'.implode(',', TaxReport::CONTRACT_TYPES)],
            'work_rate' => ['required', 'regex:/^(0(\.\d{1,2})?|1(\.0{1,2})?)$/', 'not_in:0,0.0,0.00'],
            'workplace_type' => ['nullable', 'integer', 'in:'.implode(',', TaxReport::WORKPLACE_TYPES)],
            'monthly_salary' => ['required', Input::MONEY],
            'salary_from' => ['nullable', 'date_format:Y-m'],
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'hired_at' => ['nullable', 'date_format:Y-m-d'],
            'fired_at' => ['nullable', 'date_format:Y-m-d'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'benefits_mode' => ['nullable', 'in:default,none,custom'],
            'benefits' => ['array', 'max:'.TaxReport::MAX_BENEFITS],
            'benefits.*.code' => ['required', 'regex:/^\d{1,10}$/', 'distinct'],
            'benefits.*.kind' => ['required', 'in:percent,amount'],
            'benefits.*.value' => ['required', 'regex:/^\d{1,16}(\.\d{1,3})?$/'],
        ]) + ['is_active' => $request->boolean('is_active', true)];
        foreach ($data['benefits'] ?? [] as $i => $b) {
            if ($b['kind'] === 'percent' && Accounting::dec($b['value'])->isGreaterThan(100)) {
                throw ValidationException::withMessages(["benefits.$i.value" => t('accounting.errors.benefitPercent')]);
            }
        }
        $mode = $data['benefits_mode'] ?? 'default';
        $data['benefits'] = match ($mode) {
            'none' => [],
            'custom' => array_map(fn ($b) => $b['kind'] === 'percent' ? ['code' => $b['code'], 'percent' => $b['value']] : ['code' => $b['code'], 'amount' => $b['value']], $data['benefits'] ?? []),
            default => null,
        };
        if ($mode === 'custom' && $data['benefits'] === []) {
            throw ValidationException::withMessages(['benefits' => t('accounting.errors.benefitsEmpty')]);
        }
        unset($data['benefits_mode']);

        return $data;
    }

    public function store(Request $request): RedirectResponse
    {
        TaxPayrollService::savePerson(null, $this->input($request));

        return back()->with('ok', t('accounting.people.created'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        TaxPayrollService::savePerson($id, $this->input($request));

        return redirect('/accounting/people')->with('ok', t('accounting.people.saved'));
    }

    public function destroy(string $id): RedirectResponse
    {
        TaxPayrollService::deletePerson($id);

        return redirect('/accounting/people')->with('ok', t('accounting.people.deleted'));
    }

    public function destroySalary(string $id, string $salaryId): RedirectResponse
    {
        TaxPayrollService::deleteSalary($id, $salaryId);

        return redirect('/accounting/people?edit='.$id)->with('ok', t('accounting.people.salaryDeleted'));
    }
}
