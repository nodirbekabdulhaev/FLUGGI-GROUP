<?php

namespace App\Http\Controllers\Smm;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Finance\Input;
use App\Services\Finance\Period;
use App\Services\Smm\SmmFinance;
use App\Services\Smm\SmmVideoPay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** SMM → «Финансы»: оплата SMM за месяц, ставки за ролик, прочие расходы SMM, итоги и ROI. */
class FinanceController extends Controller
{
    public function index(Request $request): View
    {
        SmmFinance::authorize();
        $period = Period::month($request->query('month'));

        return view('smm.finance', SmmFinance::month($period) + [
            'period' => $period,
            'settings' => SmmVideoPay::settings(),
            'personal' => User::with('employee:id,user_id,smm_video_rate')->whereHas('employee', fn ($q) => $q->whereNotNull('smm_video_rate'))
                ->where('status', 'ACTIVE')->orderBy('full_name')->get(['id', 'full_name']),
            'canAccounting' => access()->can('accounting.manage'),
            'accounts' => access()->can('accounting.manage') ? SmmFinance::accounts() : collect(),
            'articles' => access()->can('accounting.manage') ? SmmFinance::articles() : [],
            'defaultArticle' => SmmFinance::defaultArticle(),
            'canPersonal' => access()->can('payroll.manage', 'ALL'),
        ]);
    }

    public function rates(Request $request): RedirectResponse
    {
        SmmFinance::authorize();
        Input::normalize($request, ['rate_videographer', 'rate_mobilographer', 'rate_editor']);
        $data = $request->validate([
            'rate_videographer' => ['nullable', Input::MONEY],
            'rate_mobilographer' => ['nullable', Input::MONEY],
            'rate_editor' => ['nullable', Input::MONEY],
            'pay_assignee' => ['nullable', 'boolean'],
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ], [], [
            'rate_videographer' => t('smm.finance.roles.VIDEOGRAPHER'), 'rate_mobilographer' => t('smm.finance.roles.MOBILOGRAPHER'), 'rate_editor' => t('smm.finance.roles.EDITOR'),
        ]);
        SmmVideoPay::saveSettings([
            'VIDEOGRAPHER' => $data['rate_videographer'] ?? '0',
            'MOBILOGRAPHER' => $data['rate_mobilographer'] ?? '0',
            'EDITOR' => $data['rate_editor'] ?? '0',
        ], (bool) ($data['pay_assignee'] ?? false));

        return redirect('/smm/finance?month='.Period::month($data['month'] ?? null))->with('ok', t('smm.finance.ratesSaved'));
    }

    /** Личная ставка за ролик в карточке сотрудника (окно «Ставки»). */
    public function personalRate(Request $request, string $id): RedirectResponse
    {
        Input::normalize($request, ['smm_video_rate']);
        $data = $request->validate(['smm_video_rate' => ['nullable', Input::MONEY]], [], ['smm_video_rate' => t('smm.finance.personalRateField')]);
        SmmVideoPay::savePersonalRate($id, $data['smm_video_rate'] ?? null);

        return back()->with('ok', t('smm.finance.personalRateSaved'));
    }

    public function storeExpense(Request $request): RedirectResponse
    {
        Input::normalize($request, ['amount']);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'category' => ['required', 'string', 'max:100'],
            'amount' => ['required', Input::MONEY],
            'comment' => ['nullable', 'string', 'max:1000'],
            'to_accounting' => ['nullable', 'boolean'],
            'account_id' => ['nullable', 'required_if:to_accounting,1', 'uuid'],
            'article' => ['nullable', 'string', 'max:36'],
        ], ['account_id.required_if' => t('smm.finance.errors.accountRequired')], [
            'date' => t('smm.finance.date'), 'category' => t('smm.finance.category'), 'amount' => t('smm.finance.amount'),
            'comment' => t('smm.finance.comment'), 'account_id' => t('smm.finance.account'),
        ]);
        $x = SmmFinance::addExpense($data);

        return redirect('/smm/finance?month='.substr($x->date->toDateString(), 0, 7))->with('ok', t('smm.finance.expenseAdded'));
    }

    public function destroyExpense(Request $request, string $id): RedirectResponse
    {
        SmmFinance::deleteExpense($id);

        return back()->with('ok', t('smm.finance.expenseDeleted'));
    }
}
