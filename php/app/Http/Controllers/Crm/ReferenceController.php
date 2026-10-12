<?php

namespace App\Http\Controllers\Crm;

use App\Models\DealStage;
use App\Models\Direction;
use App\Models\ExchangeRate;
use App\Models\LeadSource;
use App\Models\LossReason;
use App\Models\Service;
use App\Services\Crm\Crm;
use App\Services\Crm\ReferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Настройки → Справочники CRM. Менять может только reference.manage (ALL); курс USD — CEO (finance.company.read). */
class ReferenceController extends CrmController
{
    public function __construct(private readonly ReferenceService $refs) {}

    public function index(): View
    {
        return view('crm.settings.references', [
            'services' => Service::with('direction')->orderBy('sort')->orderBy('name_ru')->get(),
            'directions' => Direction::orderBy('sort')->orderBy('name')->get(),
            'sources' => LeadSource::orderBy('sort')->orderBy('name_ru')->get(),
            'lossReasons' => LossReason::orderBy('sort')->orderBy('name_ru')->get(),
            'stages' => Crm::stages(),
            'rate' => ExchangeRate::with('setBy')->where('currency', 'USD')->orderByDesc('date')->first(),
            'canEdit' => access()->can('reference.manage', 'ALL'),
        ]);
    }

    public function storeService(Request $request): RedirectResponse|JsonResponse
    {
        $this->refs->saveService(null, $this->serviceInput($request, null));

        return $this->done($request, t('crm.common.saved'));
    }

    public function updateService(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $service = Service::findOrFail($id);
        $this->refs->saveService($service, $this->serviceInput($request, $service->id));

        return $this->done($request, t('crm.common.saved'));
    }

    public function storeItem(Request $request, string $kind): RedirectResponse|JsonResponse
    {
        $this->refs->saveItem($kind, null, $this->itemInput($request, $kind, null));

        return $this->done($request, t('crm.common.saved'));
    }

    public function updateItem(Request $request, string $kind, string $id): RedirectResponse|JsonResponse
    {
        $this->refs->saveItem($kind, $id, $this->itemInput($request, $kind, $id));

        return $this->done($request, t('crm.common.saved'));
    }

    public function updateStage(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $stage = DealStage::findOrFail($id);
        $data = $this->check($request, [
            'name' => ['nullable', 'string', 'min:1', 'max:80'],
            'name_uz' => ['nullable', 'string', 'max:80'],
            'name_en' => ['nullable', 'string', 'max:80'],
            'probability' => ['nullable', 'integer', 'between:0,100'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $this->refs->updateStage($stage, $data);

        return $this->done($request, t('references.stageSaved'));
    }

    public function rate(Request $request): RedirectResponse|JsonResponse
    {
        $data = $this->check($request, [
            'rate_to_uzs' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ], ['rate_to_uzs.gt' => t('references.errors.ratePositive'), 'rate_to_uzs.required' => t('references.errors.ratePositive')], ['rate_to_uzs']);
        $this->refs->setRate((string) $data['rate_to_uzs'], $data['date'] ?? null);

        return $this->done($request, t('references.rateSet'));
    }

    public function storeDirection(Request $request): RedirectResponse|JsonResponse
    {
        $this->refs->saveDirection(null, $this->directionInput($request));

        return $this->done($request, t('crm.common.saved'));
    }

    public function updateDirection(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $this->refs->saveDirection(Direction::findOrFail($id), $this->directionInput($request));

        return $this->done($request, t('crm.common.saved'));
    }

    private function serviceInput(Request $request, ?string $id): array
    {
        return $this->check($request, [
            'code' => ['required', 'string', 'min:2', 'max:40', 'regex:/^[A-Z0-9_]+$/', Rule::unique('services', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:120'],
            'name_uz' => ['nullable', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'base_price' => ['nullable', ...self::MONEY],
            'min_price' => ['nullable', ...self::MONEY],
            'currency' => ['required', Rule::in(Crm::CURRENCIES)],
            'pricing_type' => ['required', Rule::in(Crm::PRICING_TYPES)],
            'direction_id' => ['nullable', 'uuid', Rule::exists('directions', 'id')],
            'sort' => ['nullable', 'integer', 'between:0,10000'],
        ], $this->codeMessages(), ['base_price', 'min_price']) + ['is_active' => $request->boolean('is_active')];
    }

    private function itemInput(Request $request, string $kind, ?string $id): array
    {
        $model = ReferenceService::KINDS[$kind] ?? abort(404);
        $table = (new $model)->getTable();

        return $this->check($request, [
            'code' => ['required', 'string', 'min:2', 'max:40', 'regex:/^[A-Z0-9_]+$/', Rule::unique($table, 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:120'],
            'name_uz' => ['nullable', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'sort' => ['nullable', 'integer', 'between:0,10000'],
        ], $this->codeMessages()) + [
            'is_active' => $request->boolean('is_active'),
            'requires_comment' => $request->boolean('requires_comment'),
        ];
    }

    private function directionInput(Request $request): array
    {
        return $this->check($request, [
            'name' => ['required', 'string', 'max:80'],
            'sort' => ['nullable', 'integer', 'between:0,1000'],
        ], ['name.required' => t('references.errors.nameRequired')]) + ['is_active' => $request->boolean('is_active')];
    }

    private function codeMessages(): array
    {
        return [
            'code.regex' => t('references.errors.code'),
            'code.unique' => t('references.errors.codeTaken'),
            'name.required' => t('references.errors.nameRequired'),
        ];
    }
}
