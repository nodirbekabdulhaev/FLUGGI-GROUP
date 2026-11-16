<?php

namespace App\Http\Controllers\Crm;

use App\Models\Lead;
use App\Models\LossReason;
use App\Models\Service;
use App\Services\Crm\Crm;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\DealService;
use App\Services\Crm\DealStages;
use App\Services\Crm\PipelineService;
use App\Services\Crm\TimelineService;
use App\Services\Smm\SmmIdeaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DealController extends CrmController
{
    public function __construct(private readonly DealService $deals) {}

    public function index(Request $request): View
    {
        $f = $this->check($request, DealService::filterRules(), [], DealService::MONEY_FILTERS);
        $status = $f['status'] ?? 'OPEN';
        // Вид: Kanban (по умолчанию) или список; выбор помнит браузер (localStorage) и ?view=
        $view = $request->query('view') === 'list' ? 'list' : 'kanban';
        $deals = $view === 'list'
            ? DealService::filtered(CrmAccess::deals()->with(['client', 'owner', 'stage']), $f)->orderByDesc('created_at')->paginate(25)->withQueryString()
            : null;

        return view('crm.deals.index', [
            'deals' => $deals,
            'view' => $view,
            'board' => $view === 'kanban' ? PipelineService::board($f, 'DEAL') : null,
            'f' => $f + ['status' => $status],
            'filtered' => collect($f)->except('status')->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty() || $status !== 'OPEN',
            'services' => Crm::allOptions(Service::class),
            'serviceOptions' => Crm::options(Service::class),
            'owners' => access()->can('deal.read', 'TEAM') ? Crm::assignableUsers(true) : [],
            'clients' => access()->can('deal.create') && access()->can('client.read')
                ? CrmAccess::clients()->with('contacts')->orderBy('name')->limit(200)->get() : collect(),
        ]);
    }

    public function show(string $id): View
    {
        $deal = CrmAccess::deal($id);
        $deal->load(['client.contacts', 'contact', 'owner', 'team', 'service', 'stage', 'lossReason', 'smmIdea']);
        $target = ['deal_id' => $deal->id];

        return view('crm.deals.show', [
            'deal' => $deal,
            'leadId' => Lead::where('deal_id', $deal->id)->value('id'),
            'stages' => Crm::stages('DEAL'),
            'activities' => TimelineService::activities($target),
            'history' => TimelineService::history($target),
            'comments' => TimelineService::comments($target),
            'meetings' => CrmAccess::meetings()->where('deal_id', $deal->id)->with(['manager', 'rop', 'lead', 'deal', 'participants'])->orderBy('starts_at')->get(),
            'money' => access()->can('payment.read') ? DealService::money($deal) : null,
            'serviceOptions' => Crm::options(Service::class, $deal->service_id),
            'smmVideos' => SmmIdeaService::publishedOptions($deal->smm_idea_id),
            'owners' => Crm::assignableUsers(),
            'reasons' => Crm::options(LossReason::class),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $this->check($request, [
            'client_id' => ['required', 'uuid'],
            'contact_id' => ['nullable', 'uuid'],
            'title' => ['required', 'string', 'max:200'],
            'service_id' => ['nullable', 'uuid'],
            'amount' => ['required', ...self::MONEY],
            'currency' => ['nullable', Rule::in(Crm::CURRENCIES)],
            'owner_id' => ['nullable', 'uuid'],
            'expected_close_date' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'client_id.required' => t('deals.errors.selectClient'),
            'title.required' => t('deals.errors.titleRequired'),
            'amount.regex' => t('crm.validation.money'),
        ], ['amount']);
        $deal = $this->deals->create($data);

        return $this->done($request, t('crm.common.created'), route('crm.deals.show', $deal->id), ['id' => $deal->id]);
    }

    public function update(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $deal = CrmAccess::deal($id, 'deal.update');
        $data = $this->check($request, [
            'title' => ['required', 'string', 'max:200'],
            'contact_id' => ['nullable', 'uuid'],
            'service_id' => ['nullable', 'uuid'],
            'amount' => ['required', ...self::MONEY],
            'currency' => ['required', Rule::in(Crm::CURRENCIES)],
            'expected_close_date' => ['nullable', 'date_format:Y-m-d'],
            'probability_override' => ['nullable', 'integer', 'between:0,100'],
            'smm_idea_id' => ['sometimes', 'nullable', 'uuid'],
        ], ['amount.regex' => t('crm.validation.money')], ['amount']);
        foreach (['contact_id', 'service_id', 'expected_close_date', 'probability_override'] as $k) {
            $data[$k] ??= null;
        }
        $this->deals->update($deal, $data);

        return $this->done($request, t('crm.common.saved'));
    }

    public function stage(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $deal = CrmAccess::deal($id, 'deal.change_stage');
        $code = $this->check($request, ['stage' => ['required', Rule::in(DealStages::CODES)]])['stage'];
        $this->deals->changeStage($deal, $code);

        return $this->done($request, t('crm.common.stageChanged'));
    }

    public function assign(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $deal = CrmAccess::deal($id, 'deal.update');
        $ownerId = $this->check($request, ['owner_id' => ['required', 'uuid']], ['owner_id.required' => t('crm.errors.selectOwner')])['owner_id'];
        $this->deals->assign($deal, $ownerId);

        return $this->done($request, t('crm.common.assigned'));
    }

    public function close(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $deal = CrmAccess::deal($id, 'deal.update');
        $this->deals->close($deal, $this->check($request, [
            'status' => ['required', Rule::in(Crm::CLOSE_STATUSES)],
            'loss_reason_id' => ['nullable', 'uuid'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]));

        return $this->done($request, t('crm.common.closed'));
    }

    public function reopen(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $this->deals->reopen(CrmAccess::deal($id, 'deal.update'));

        return $this->done($request, t('crm.common.reopened'));
    }

    public function destroy(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $this->deals->remove(CrmAccess::deal($id, 'deal.delete'));

        return $this->done($request, t('crm.common.deleted'), route('crm.deals.index'));
    }
}
