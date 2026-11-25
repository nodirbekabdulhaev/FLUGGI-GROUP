<?php

namespace App\Http\Controllers\Crm;

use App\Models\LeadSource;
use App\Models\Service;
use App\Services\Crm\Crm;
use App\Services\Crm\DealService;
use App\Services\Crm\LeadService;
use App\Services\Crm\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Воронка (Kanban): страница и JSON для обновления после перетаскивания. */
class PipelineController extends CrmController
{
    public function show(Request $request): View
    {
        $f = $this->filters($request);

        return view('crm.pipeline.show', [
            'board' => PipelineService::board($f),
            'f' => $f,
            'services' => Crm::allOptions(Service::class),
            'sources' => Crm::allOptions(LeadSource::class),
            'owners' => access()->can('deal.read', 'TEAM') || access()->can('lead.read', 'TEAM') ? Crm::assignableUsers(true) : [],
        ]);
    }

    /** JSON доски. only=LEAD|DEAL — Kanban страниц «Лиды»/«Сделки» с фильтрами этих списков. */
    public function data(Request $request): JsonResponse
    {
        $only = $this->check($request, ['only' => ['nullable', Rule::in(['LEAD', 'DEAL'])]])['only'] ?? null;
        $f = match ($only) {
            'LEAD' => $this->check($request, LeadService::filterRules()),
            'DEAL' => $this->check($request, DealService::filterRules(), [], DealService::MONEY_FILTERS),
            default => $this->filters($request),
        };

        return response()->json(PipelineService::board($f, $only));
    }

    private function filters(Request $request): array
    {
        return $this->check($request, [
            'owner_id' => ['nullable', 'uuid'],
            'team_id' => ['nullable', 'uuid'],
            'service_id' => ['nullable', 'uuid'],
            'source_id' => ['nullable', 'uuid'],
        ]);
    }
}
