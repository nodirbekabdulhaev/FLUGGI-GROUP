<?php

namespace App\Http\Controllers\Sales;

use App\Models\User;
use App\Services\Sales\Numbers;
use App\Services\Sales\PaymentsService;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Оплаты (ТЗ §18–19): список, создание, исправление до подтверждения, подтверждение, отмена, возврат. */
class PaymentController extends SalesController
{
    public function index(Request $request): View
    {
        $f = $this->check($request, [
            'status' => ['nullable', Rule::in(PaymentsService::STATUSES)],
            'type' => ['nullable', Rule::in(PaymentsService::TYPES)],
            'deal_id' => ['nullable', 'uuid'],
            'client_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return view('sales.payments.index', ['items' => PaymentsService::list($f), 'f' => $f]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $this->nulls($this->check($request, [
            'deal_id' => ['required', 'uuid'],
            'contract_id' => ['nullable', 'uuid'],
            'amount' => ['required', 'numeric', ...self::MONEY, 'gt:0'],
            'currency' => ['required', Rule::in(self::CURRENCIES)],
            'type' => ['required', Rule::in(PaymentsService::CREATE_TYPES)],
            'method' => ['required', Rule::in(PaymentsService::METHODS)],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], ['amount'], ['amount.gt' => t('sales.errors.amountPositive')]), ['contract_id', 'due_date', 'comment']);
        $p = PaymentsService::create($data);

        return $this->done($request, t('sales.payments.created'), null, ['id' => $p->id, 'number' => Numbers::payment($p->number)]);
    }

    public function update(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $data = $this->nulls($this->check($request, [
            'contract_id' => ['sometimes', 'nullable', 'uuid'],
            'amount' => ['sometimes', 'required', 'numeric', ...self::MONEY, 'gt:0'],
            'currency' => ['sometimes', 'required', Rule::in(self::CURRENCIES)],
            'type' => ['sometimes', 'required', Rule::in(PaymentsService::CREATE_TYPES)],
            'method' => ['sometimes', 'required', Rule::in(PaymentsService::METHODS)],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], ['amount'], ['amount.gt' => t('sales.errors.amountPositive')]), ['contract_id', 'due_date', 'comment']);
        PaymentsService::update($id, $data);

        return $this->done($request, t('sales.payments.updated'));
    }

    public function confirm(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $data = $this->check($request, ['paid_at' => ['nullable', 'date_format:Y-m-d\TH:i']]);
        $paidAt = ! empty($data['paid_at']) ? Format::fromLocalInput($data['paid_at']) : null;
        $res = PaymentsService::confirm($id, $paidAt instanceof CarbonImmutable ? $paidAt : null);
        $lines = [t('sales.payments.confirmed')];
        if ($res['projectCreated']) {
            $lines[] = t('sales.payments.projectCreated', ['number' => Numbers::project($res['project']->number)]);
        }
        if ($res['commissions']) {
            $names = User::whereIn('id', array_map(fn ($c) => $c->user_id, $res['commissions']))->pluck('full_name', 'id');
            $lines[] = t('sales.payments.commissions', ['list' => implode(', ', array_map(
                fn ($c) => ($names[$c->user_id] ?? '').' '.money((string) $c->amount_uzs),
                $res['commissions'],
            ))]);
        }

        return $this->done($request, implode('. ', $lines), null, [
            'projectCreated' => $res['projectCreated'],
            'project' => ['id' => $res['project']->id, 'number' => Numbers::project($res['project']->number), 'name' => $res['project']->name],
            'commissions' => array_map(fn ($c) => ['id' => $c->id, 'role' => $c->role, 'userId' => $c->user_id, 'rate' => Numbers::rate($c->rate), 'amountUzs' => (string) $c->amount_uzs], $res['commissions']),
        ]);
    }

    public function refund(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $data = $this->check($request, [
            'amount' => ['required', 'numeric', ...self::MONEY, 'gt:0'],
            'method' => ['required', Rule::in(PaymentsService::METHODS)],
            'comment' => ['required', 'string', 'max:2000'],
        ], ['amount'], ['amount.gt' => t('sales.errors.amountPositive'), 'comment.required' => t('sales.errors.refundReason')]);
        $r = PaymentsService::refund($id, $data);

        return $this->done($request, t('sales.payments.refunded'), null, ['id' => $r->id]);
    }

    public function cancel(Request $request, string $id): RedirectResponse|JsonResponse
    {
        PaymentsService::cancel($id);

        return $this->done($request, t('sales.payments.cancelled'));
    }

    /** Сводка денег по сделке для карточки (JSON). */
    public function money(string $id): JsonResponse
    {
        return response()->json(PaymentsService::dealMoney($id));
    }
}
