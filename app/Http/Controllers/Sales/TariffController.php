<?php

namespace App\Http\Controllers\Sales;

use App\Models\Service;
use App\Models\User;
use App\Services\Sales\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Настройки → «Тарифы»: тарифы услуг, единицы работ; личные сдельные ставки сотрудников. */
class TariffController extends SalesController
{
    public function index(): View
    {
        return view('sales.tariffs.index', [
            'services' => Service::orderBy('sort')->orderBy('name_ru')->get(),
            'tariffs' => CatalogService::tariffs(true)->groupBy('service_id'),
            'workItems' => CatalogService::workItems(),
        ]);
    }

    /** Активные тарифы (для КП), экономика — только с правом на финансы компании. */
    public function list(Request $request): JsonResponse
    {
        $f = $this->check($request, ['service_id' => ['nullable', 'uuid'], 'all' => ['nullable', 'boolean']]);

        return response()->json(CatalogService::tariffs((bool) ($f['all'] ?? false), $f['service_id'] ?? null)->map(fn ($t) => [
            'id' => $t->id,
            'service' => ['id' => $t->service->id, 'name' => $t->service->name_ru],
            'name' => $t->name,
            'description' => $t->description,
            'price' => (string) $t->price,
            'currency' => $t->currency,
            'isActive' => $t->is_active,
            'economics' => $t->economics,
        ])->all());
    }

    private function tariffInput(Request $request): array
    {
        $data = $this->check($request, [
            'service_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', ...self::MONEY, 'gt:0'],
            'currency' => ['required', Rule::in(self::CURRENCIES)],
            'is_active' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'items' => ['nullable', 'array', 'max:50'],
            'items.*.kind' => ['required', Rule::in(['PIECE', 'FIXED'])],
            'items.*.work_item_id' => ['nullable', 'required_if:items.*.kind,PIECE', 'uuid', 'exists:work_items,id'],
            'items.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'items.*.specialty' => ['nullable', 'required_if:items.*.kind,FIXED', Rule::in(CatalogService::SPECIALTIES)],
            'items.*.amount' => ['nullable', 'required_if:items.*.kind,FIXED', ...self::MONEY],
            'items.*.currency' => ['nullable', Rule::in(self::CURRENCIES)],
            'items.*.label' => ['nullable', 'string', 'max:120'],
        ], ['price', 'items.*.quantity', 'items.*.amount'], [
            'price.gt' => t('tariffs.errors.pricePositive'),
            'items.*.work_item_id.required_if' => t('tariffs.errors.itemRequired'),
            'items.*.specialty.required_if' => t('tariffs.errors.itemRequired'),
            'items.*.amount.required_if' => t('tariffs.errors.itemRequired'),
        ]);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['items'] = array_map(fn ($i) => $i + ['quantity' => 1, 'currency' => 'UZS'], array_values($data['items'] ?? []));

        return $data;
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $t = CatalogService::saveTariff(null, $this->tariffInput($request));

        return $this->done($request, t('tariffs.saved'), null, ['id' => $t->id]);
    }

    public function update(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $t = CatalogService::saveTariff($id, $this->tariffInput($request));

        return $this->done($request, t('tariffs.saved'), null, ['id' => $t->id]);
    }

    private function workItemInput(Request $request): array
    {
        $data = $this->check($request, [
            'name' => ['required', 'string', 'max:120'],
            'unit' => ['nullable', 'string', 'max:20'],
            'specialty' => ['nullable', Rule::in(CatalogService::SPECIALTIES)],
            'default_rate' => ['required', ...self::MONEY],
            'currency' => ['required', Rule::in(self::CURRENCIES)],
            'is_active' => ['nullable', 'boolean'],
        ], ['default_rate']);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['unit'] = $data['unit'] ?? '';

        return $data;
    }

    public function storeWorkItem(Request $request): RedirectResponse|JsonResponse
    {
        $w = CatalogService::saveWorkItem(null, $this->workItemInput($request));

        return $this->done($request, t('tariffs.workItemSaved'), null, ['id' => $w->id]);
    }

    public function updateWorkItem(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $w = CatalogService::saveWorkItem($id, $this->workItemInput($request));

        return $this->done($request, t('tariffs.workItemSaved'), null, ['id' => $w->id]);
    }

    // ─────────────── Личные ставки ───────────────

    public function rates(Request $request, string $id): View|JsonResponse
    {
        $rates = CatalogService::employeeRates($id);
        $user = User::findOrFail($id);
        if ($request->expectsJson()) {
            return response()->json($rates->map(fn ($r) => [
                'workItem' => ['id' => $r->workItem->id, 'code' => $r->workItem->code, 'name' => $r->workItem->name, 'defaultRate' => (string) $r->workItem->default_rate, 'currency' => $r->workItem->currency],
                'rate' => $r->rate,
                'currency' => $r->currency,
            ])->all());
        }

        return view('sales.rates.show', ['user' => $user, 'rates' => $rates]);
    }

    public function saveRates(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $data = $this->check($request, [
            'rates' => ['present', 'array', 'max:200'],
            'rates.*.work_item_id' => ['required', 'uuid', 'exists:work_items,id'],
            'rates.*.rate' => ['nullable', ...self::MONEY],
            'rates.*.currency' => ['required', Rule::in(self::CURRENCIES)],
        ], ['rates.*.rate']);
        CatalogService::saveEmployeeRates($id, array_map(fn ($r) => $r + ['rate' => null], array_values($data['rates'] ?? [])));

        return $this->done($request, t('rates.saved'));
    }
}
