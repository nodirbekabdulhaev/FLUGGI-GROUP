<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccPerson;
use App\Models\User;
use App\Services\Accounting\TaxPayrollService;
use App\Services\Finance\Input;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Люди, оформленные для налоговой (не обязательно сотрудники CRM). */
class PeopleController extends Controller
{
    public function index(Request $request): View
    {
        $canManage = access()->can('accounting.manage');

        return view('accounting.people', [
            'people' => TaxPayrollService::people(),
            'users' => $canManage ? User::where('status', 'ACTIVE')->orderBy('full_name')->pluck('full_name', 'id')->all() : [],
            'canManage' => $canManage,
            'editing' => $canManage && ($id = $request->query('edit')) ? AccPerson::findOrFail($id) : null,
        ]);
    }

    private function input(Request $request): array
    {
        Input::normalize($request, ['monthly_salary']);
        $request->merge(['pinfl' => preg_replace('/\s+/', '', (string) $request->input('pinfl')) ?: null]);

        return $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:191'],
            'pinfl' => ['nullable', 'regex:/^\d{14}$/'],
            'position' => ['nullable', 'string', 'max:191'],
            'monthly_salary' => ['required', Input::MONEY],
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'hired_at' => ['nullable', 'date_format:Y-m-d'],
            'fired_at' => ['nullable', 'date_format:Y-m-d'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]) + ['is_active' => $request->boolean('is_active', true)];
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
}
