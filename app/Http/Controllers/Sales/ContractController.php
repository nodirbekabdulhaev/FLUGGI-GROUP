<?php

namespace App\Http\Controllers\Sales;

use App\Services\Sales\ContractsService;
use App\Services\Sales\Numbers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Договоры (ТЗ §17): список, создание из КП, правка до подписи, статусы. */
class ContractController extends SalesController
{
    public function index(Request $request): View
    {
        $f = $this->check($request, [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(ContractsService::STATUSES)],
            'deal_id' => ['nullable', 'uuid'],
        ]);

        return view('sales.contracts.index', ['items' => ContractsService::list($f), 'f' => $f]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $this->nulls($this->check($request, [
            'deal_id' => ['required', 'uuid'],
            'proposal_id' => ['nullable', 'uuid'],
            'contract_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', ...self::MONEY],
            'currency' => ['required', Rule::in(self::CURRENCIES)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], ['amount']), ['proposal_id', 'comment']);
        $c = ContractsService::create($data);

        return $this->done($request, t('sales.contracts.created'), null, ['id' => $c->id, 'number' => Numbers::contract($c->number)]);
    }

    public function update(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $data = $this->nulls($this->check($request, [
            'contract_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'amount' => ['sometimes', 'required', ...self::MONEY],
            'currency' => ['sometimes', 'required', Rule::in(self::CURRENCIES)],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], ['amount']), ['comment']);
        ContractsService::update($id, $data);

        return $this->done($request, t('sales.contracts.updated'));
    }

    public function action(Request $request, string $id, string $action): RedirectResponse|JsonResponse
    {
        if (! array_key_exists($action, ContractsService::TRANSITIONS)) {
            abort(404);
        }
        $c = ContractsService::transition($id, $action);

        return $this->done($request, t('sales.contracts.statusChanged'), null, ['id' => $c->id, 'status' => $c->status]);
    }
}
