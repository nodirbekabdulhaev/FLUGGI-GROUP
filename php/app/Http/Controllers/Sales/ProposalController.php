<?php

namespace App\Http\Controllers\Sales;

use App\Services\Sales\Numbers;
use App\Services\Sales\ProposalsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** КП: список, создание и правка (новая версия), согласование, отправка, принятие (ТЗ §15–16). */
class ProposalController extends SalesController
{
    public function index(Request $request): View
    {
        $f = $this->check($request, [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(ProposalsService::STATUSES)],
            'deal_id' => ['nullable', 'uuid'],
            'manager_id' => ['nullable', 'uuid'],
        ]);

        return view('sales.proposals.index', ['items' => ProposalsService::list($f), 'f' => $f]);
    }

    private function input(Request $request, bool $create): array
    {
        $data = $this->check($request, ($create ? ['deal_id' => ['required', 'uuid']] : []) + [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'currency' => ['required', Rule::in(self::CURRENCIES)],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.service_id' => ['nullable', 'uuid', 'exists:services,id'],
            'items.*.tariff_id' => ['nullable', 'uuid'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'items.*.unit_price' => ['required', ...self::MONEY],
            'items.*.discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'implementation_term' => ['nullable', 'string', 'max:200'],
            'payment_terms' => ['nullable', 'string', 'max:1000'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'version_comment' => ['nullable', 'string', 'max:500'],
        ], ['items.*.quantity', 'items.*.unit_price', 'items.*.discount_pct'], [
            'items.required' => t('sales.errors.itemsRequired'),
            'items.min' => t('sales.errors.itemsRequired'),
        ]);
        $data['items'] = array_map(fn ($i) => $i + ['discount_pct' => 0, 'service_id' => null, 'tariff_id' => null], array_values($data['items']));

        return $data;
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $p = ProposalsService::create($this->input($request, true));

        return $this->done($request, t('sales.proposals.saved', ['v' => $p->current_version]), null, ['id' => $p->id, 'number' => Numbers::proposal($p->number)]);
    }

    public function update(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $p = ProposalsService::update($id, $this->input($request, false));

        return $this->done($request, t('sales.proposals.saved', ['v' => $p->current_version]), null, ['id' => $p->id]);
    }

    /** Действие со статусом; у каждого действия своё право (утвердить — РОП, отправить — proposal.send). */
    public function action(Request $request, string $id, string $action): RedirectResponse|JsonResponse
    {
        if (! in_array($action, ProposalsService::ACTIONS, true)) {
            abort(404);
        }
        access()->authorize(ProposalsService::permissionFor($action));
        $p = ProposalsService::action($id, $action);

        return $this->done($request, t('sales.proposals.statusChanged'), null, ['id' => $p->id, 'status' => $p->status]);
    }

    public function versions(string $id): JsonResponse
    {
        $p = ProposalsService::find($id);

        return response()->json(ProposalsService::versions($p)->map(fn ($v) => [
            'version' => $v->version,
            'total' => (string) $v->total,
            'currency' => $v->currency,
            'author' => ['id' => $v->author->id, 'name' => $v->author->full_name],
            'comment' => $v->comment,
            'createdAt' => $v->created_at->format('Y-m-d\TH:i:s.v\Z'),
        ])->all());
    }
}
