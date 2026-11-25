<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\AccLoan;
use App\Models\User;
use App\Services\Accounting\LoansService;
use App\Services\Finance\Input;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Кредиты: договоры, график платежей по месяцам, отметка «оплачено», ручной ввод и импорт графика. */
class LoansController extends Controller
{
    public function index(): View
    {
        $rows = LoansService::list();

        return view('accounting.loans', ['rows' => $rows, 'totals' => LoansService::totals($rows), 'owners' => $this->owners(), 'canManage' => access()->can('accounting.manage')]);
    }

    public function show(string $id): View
    {
        $loan = AccLoan::with(['payments', 'owner:id,full_name'])->findOrFail($id);

        return view('accounting.loan', ['loan' => $loan, 'stats' => LoansService::stats($loan), 'owners' => $this->owners(), 'canManage' => access()->can('accounting.manage')]);
    }

    private function owners(): array
    {
        return User::where('status', 'ACTIVE')->orderBy('full_name')->pluck('full_name', 'id')->all();
    }

    private function loanData(Request $request): array
    {
        Input::normalize($request, ['principal', 'rate']);

        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'lender' => ['nullable', 'string', 'max:191'],
            'currency' => ['required', Rule::in(LoansService::CURRENCIES)],
            'principal' => ['required', Input::MONEY, 'not_regex:/^0+(\.0+)?$/'],
            'rate' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:2000'],
            'owner_id' => ['nullable', 'uuid', Rule::exists('users', 'id')->where('status', 'ACTIVE')],
            'remind_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function rows(Request $request): array
    {
        $request->validate(['rows' => ['nullable', 'array', 'max:'.LoansService::MAX_ROWS], 'rows.*' => ['array']]);

        return collect($request->input('rows', []))->map(fn ($r) => [
            'due_date' => $r['due_date'] ?? null, 'principal' => $r['principal'] ?? null, 'interest' => $r['interest'] ?? null, 'total' => $r['total'] ?? null,
        ])->values()->all();
    }

    public function store(Request $request): RedirectResponse
    {
        $loan = LoansService::create($this->loanData($request), $this->rows($request));

        return redirect('/accounting/loans/'.$loan->id)->with('ok', t('accounting.loans.created'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        LoansService::update($id, $this->loanData($request));

        return back()->with('ok', t('accounting.loans.saved'));
    }

    public function destroy(string $id): RedirectResponse
    {
        LoansService::delete($id);

        return redirect('/accounting/loans')->with('ok', t('accounting.loans.deleted'));
    }

    /** Ручной ввод графика: новые строки добавляются или заменяют неоплаченные. */
    public function schedule(Request $request, string $id): RedirectResponse
    {
        $n = LoansService::addSchedule($id, $this->rows($request), $request->boolean('replace'));

        return back()->with('ok', t('accounting.loans.scheduleSaved', ['n' => $n]));
    }

    public function import(Request $request, string $id): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'extensions:'.implode(',', LoansService::EXTENSIONS)]], [
            'file.required' => t('import.errors.fileRequired'),
            'file.extensions' => t('accounting.loans.errors.type'),
            'file.max' => t('import.errors.fileSize', ['mb' => 5]),
        ]);
        $file = $request->file('file');
        $rows = LoansService::parseFile($file->getRealPath(), $file->getClientOriginalName());
        $n = LoansService::addSchedule($id, $rows, $request->boolean('replace'));

        return back()->with('ok', t('accounting.loans.imported', ['n' => $n]));
    }

    /** Найти оплаты платежей этого кредита среди банковских операций. */
    public function match(string $id): RedirectResponse
    {
        $r = LoansService::autoMatch($id);

        return back()->with('ok', $r['paid'] || $r['received'] ? t('accounting.loans.matched', ['n' => $r['paid'] + $r['received']]) : t('accounting.loans.matchNone'));
    }

    public function updatePayment(Request $request, string $id): RedirectResponse
    {
        Input::normalize($request, ['principal', 'interest', 'total']);
        $d = $request->validate(['due_date' => ['required', 'date_format:Y-m-d'], 'principal' => ['nullable', Input::MONEY], 'interest' => ['nullable', Input::MONEY], 'total' => ['nullable', Input::MONEY]]);
        LoansService::updatePayment($id, $d);

        return back()->with('ok', t('accounting.loans.saved'));
    }

    public function deletePayment(string $id): RedirectResponse
    {
        LoansService::deletePayment($id);

        return back()->with('ok', t('accounting.loans.paymentDeleted'));
    }

    public function paid(Request $request, string $id): RedirectResponse
    {
        Input::normalize($request, ['paid_amount']);
        $d = $request->validate(['paid_at' => ['nullable', 'date_format:Y-m-d'], 'paid_amount' => ['nullable', Input::MONEY]]);
        LoansService::setPaid($id, true, $d['paid_at'] ?? null, $d['paid_amount'] ?? null);

        return back()->with('ok', t('accounting.loans.markedPaid'));
    }

    public function unpaid(string $id): RedirectResponse
    {
        LoansService::setPaid($id, false);

        return back()->with('ok', t('accounting.loans.markedUnpaid'));
    }
}
