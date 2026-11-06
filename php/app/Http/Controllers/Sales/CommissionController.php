<?php

namespace App\Http\Controllers\Sales;

use App\Models\User;
use App\Services\Sales\CommissionsService;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Комиссии и правила комиссий (ТЗ §32–34). */
class CommissionController extends SalesController
{
    public function index(Request $request): View
    {
        $f = $this->check($request, [
            'period' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'status' => ['nullable', Rule::in(CommissionsService::STATUSES)],
            'user_id' => ['nullable', 'uuid'],
        ]);
        // По умолчанию — текущий месяц по Ташкенту; «все периоды» — period=""
        if (! $request->has('period')) {
            $f['period'] = Format::today()->format('Y-m');
        }
        ['items' => $items, 'totalUzs' => $total] = CommissionsService::list($f);

        return view('sales.commissions.index', ['items' => $items, 'totalUzs' => $total, 'f' => $f]);
    }

    public function approve(Request $request): RedirectResponse|JsonResponse
    {
        $ids = $this->ids($request);
        $n = CommissionsService::transition($ids, 'APPROVED');

        return $this->done($request, t('sales.commissions.approved', ['count' => $n]), null, ['updated' => $n]);
    }

    public function pay(Request $request): RedirectResponse|JsonResponse
    {
        $ids = $this->ids($request);
        $n = CommissionsService::transition($ids, 'PAID');

        return $this->done($request, t('sales.commissions.paid', ['count' => $n]), null, ['updated' => $n]);
    }

    private function ids(Request $request): array
    {
        return $this->check($request, [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['uuid'],
        ], [], ['ids.required' => t('sales.errors.chooseCommissions'), 'ids.min' => t('sales.errors.chooseCommissions')])['ids'];
    }

    // ─────────────── Правила ───────────────

    public function rules(): View
    {
        return view('sales.commission-rules.index', [
            'rules' => CommissionsService::rules(),
            'users' => User::query()->whereHas('role', fn ($q) => $q->whereIn('code', ['MANAGER', 'ROP', 'EXECUTOR']))
                ->where('status', 'ACTIVE')->orderBy('full_name')->get(['id', 'full_name']),
        ]);
    }

    private function ruleInput(Request $request): array
    {
        $data = $this->check($request, [
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'applies_to' => ['required', Rule::in(CommissionsService::ROLES)],
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'calc_type' => ['required', Rule::in(CommissionsService::CALC_TYPES)],
            'value' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'mode' => ['nullable', Rule::in(['all', 'any'])],
            'leaves' => ['nullable', 'array', 'max:20'],
            'leaves.*.metric' => ['required', Rule::in(CommissionsService::METRICS)],
            'leaves.*.op' => ['required', Rule::in(CommissionsService::OPS)],
            'leaves.*.value' => ['required', 'numeric'],
        ], ['value', 'leaves.*.value']);
        $leaves = array_map(fn ($l) => ['metric' => $l['metric'], 'op' => $l['op'], 'value' => $l['value'] + 0], array_values($data['leaves'] ?? []));

        return [
            'name' => $data['name'],
            'applies_to' => $data['applies_to'],
            'user_id' => ($data['user_id'] ?? null) ?: null,
            'calc_type' => $data['calc_type'],
            'value' => $data['value'],
            'priority' => (int) ($data['priority'] ?? 0),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'conditions' => $leaves ? [($data['mode'] ?? 'all') => $leaves] : null,
        ];
    }

    public function storeRule(Request $request): RedirectResponse|JsonResponse
    {
        $r = CommissionsService::saveRule(null, $this->ruleInput($request));

        return $this->done($request, t('commissionRules.saved'), null, ['id' => $r->id]);
    }

    public function updateRule(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $r = CommissionsService::saveRule($id, $this->ruleInput($request));

        return $this->done($request, t('commissionRules.saved'), null, ['id' => $r->id]);
    }
}
