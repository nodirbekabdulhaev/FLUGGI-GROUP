<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccAccount;
use App\Services\Accounting\Accounting;
use App\Services\Accounting\AccountsService;
use App\Services\Finance\Input;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Счета и кошельки: банковские счета, касса, карта. */
class AccountsController extends Controller
{
    public function index(Request $request): View
    {
        $accounts = AccountsService::list();
        $canManage = access()->can('accounting.manage');
        $editing = $canManage && ($id = $request->query('edit')) ? AccAccount::findOrFail($id) : null;

        return view('accounting.accounts', ['accounts' => $accounts, 'totals' => AccountsService::totals($accounts), 'canManage' => $canManage, 'editing' => $editing]);
    }

    private function input(Request $request): array
    {
        Input::normalize($request, ['opening_balance']);

        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:191'],
            'kind' => ['required', Rule::in(Accounting::KINDS)],
            'bank_name' => ['nullable', 'string', 'max:191'],
            'account_number' => ['nullable', 'string', 'regex:/^[0-9 ]{1,64}$/'],
            'currency' => ['required', Rule::in(Accounting::CURRENCIES)],
            'opening_balance' => ['nullable', 'regex:/^-?\d{1,16}(\.\d{1,2})?$/'],
            'opening_date' => ['nullable', 'date_format:Y-m-d'],
            'crm_methods' => ['nullable', 'array'],
            'crm_methods.*' => [Rule::in(Accounting::CRM_METHODS)],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]) + ['crm_methods' => []];
    }

    private function clean(array $d): array
    {
        $d['bank_name'] = trim((string) ($d['bank_name'] ?? '')) ?: null;
        $d['account_number'] = ($n = str_replace(' ', '', (string) ($d['account_number'] ?? ''))) !== '' ? $n : null;
        $d['opening_date'] = ($d['opening_date'] ?? null) ?: null;
        $d['sort'] = (int) ($d['sort'] ?? 0);

        return $d;
    }

    public function store(Request $request): RedirectResponse
    {
        AccountsService::save(null, $this->clean($this->input($request)));

        return back()->with('ok', t('accounting.accounts.created'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        AccountsService::save($id, $this->clean($this->input($request)));

        return redirect('/accounting/accounts')->with('ok', t('accounting.accounts.saved'));
    }

    public function active(Request $request, string $id): RedirectResponse
    {
        AccountsService::setActive($id, $request->boolean('active'));

        return back()->with('ok', t('accounting.accounts.saved'));
    }

    public function destroy(string $id): RedirectResponse
    {
        AccountsService::delete($id);

        return redirect('/accounting/accounts')->with('ok', t('accounting.accounts.deleted'));
    }
}
