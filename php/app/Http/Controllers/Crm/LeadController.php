<?php

namespace App\Http\Controllers\Crm;

use App\Models\LeadSource;
use App\Models\LossReason;
use App\Models\Service;
use App\Services\Crm\Crm;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\LeadService;
use App\Services\Crm\TimelineService;
use App\Services\Smm\SmmIdeaService;
use App\Support\Format;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LeadController extends CrmController
{
    public function __construct(private readonly LeadService $leads) {}

    public function index(Request $request): View
    {
        $f = $this->check($request, [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([...Crm::LEAD_STATUSES, 'any'])],
            'stage' => ['nullable', Rule::in(Crm::LEAD_STAGES)],
            'owner_id' => ['nullable', 'uuid'],
            'team_id' => ['nullable', 'uuid'],
            'source_id' => ['nullable', 'uuid'],
            'service_id' => ['nullable', 'uuid'],
            'score' => ['nullable', Rule::in(Crm::SCORE_LEVELS)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        // По умолчанию — лиды в работе; «any» — все статусы
        $status = $f['status'] ?? 'OPEN';
        $q = CrmAccess::leads()->with(['source', 'owner', 'stage']);
        if ($s = $f['q'] ?? null) {
            $q->where(fn ($w) => collect(['title', 'contact_name', 'company_name', 'phone', 'telegram', 'email'])
                ->each(fn ($c) => $w->orWhere($c, 'like', '%'.$s.'%')));
        }
        $q->when($status !== 'any', fn ($q) => $q->where('status', $status))
            ->when($f['stage'] ?? null, fn ($q, $v) => $q->whereHas('stage', fn ($s) => $s->where('code', $v)))
            ->when($f['owner_id'] ?? null, fn ($q, $v) => $q->where('owner_id', $v))
            ->when($f['team_id'] ?? null, fn ($q, $v) => $q->where('team_id', $v))
            ->when($f['source_id'] ?? null, fn ($q, $v) => $q->where('source_id', $v))
            ->when($f['service_id'] ?? null, fn ($q, $v) => $q->where('service_id', $v))
            ->when($f['score'] ?? null, fn ($q, $v) => $q->where('score_level', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Crm::dayStart($v)))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->where('created_at', '<', Crm::dayStart($v)->addDay()));
        $leads = $q->orderByDesc('created_at')->paginate(25)->withQueryString();
        $filtered = collect($f)->except('status')->filter()->isNotEmpty() || $status !== 'OPEN';

        return view('crm.leads.index', [
            'leads' => $leads,
            'f' => $f + ['status' => $status],
            'filtered' => $filtered,
            'form' => $this->formOptions(),
            'owners' => access()->can('lead.read', 'TEAM') ? Crm::assignableUsers(true) : [],
            'formOwners' => Crm::assignableUsers(),
        ]);
    }

    public function show(string $id): View
    {
        $lead = CrmAccess::lead($id);
        $lead->load(['source', 'owner', 'team', 'service', 'stage', 'lossReason', 'smmIdea']);
        $target = ['lead_id' => $lead->id];

        return view('crm.leads.show', [
            'lead' => $lead,
            'stages' => Crm::stages('LEAD'),
            'activities' => TimelineService::activities($target),
            'history' => TimelineService::history($target),
            'comments' => TimelineService::comments($target),
            'meetings' => CrmAccess::meetings()->where('lead_id', $lead->id)->with(['manager', 'rop', 'lead', 'deal'])->orderBy('starts_at')->get(),
            'form' => $this->formOptions($lead->source_id, $lead->service_id, $lead->smm_idea_id),
            'owners' => Crm::assignableUsers(),
            'reasons' => Crm::options(LossReason::class),
            'clients' => access()->can('client.read')
                ? CrmAccess::clients()->when($lead->company_name, fn ($q, $v) => $q->where('name', 'like', '%'.$v.'%'))->orderBy('name')->limit(20)->get()
                : collect(),
            'duplicates' => LeadService::duplicates($lead->phone, $lead->instagram, $lead->email, $lead->id),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $this->validated($request, create: true);
        $lead = $this->leads->create($data);

        return $this->done($request, t('crm.common.created'), route('crm.leads.show', $lead->id), ['id' => $lead->id]);
    }

    public function update(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $lead = CrmAccess::lead($id, 'lead.update');
        $this->leads->update($lead, $this->validated($request, create: false));

        return $this->done($request, t('crm.common.saved'));
    }

    public function stage(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $lead = CrmAccess::lead($id, 'lead.update');
        $code = $this->check($request, ['stage' => ['required', Rule::in(Crm::LEAD_STAGES)]])['stage'];
        $this->leads->changeStage($lead, $code);

        return $this->done($request, t('crm.common.stageChanged'));
    }

    public function assign(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $lead = CrmAccess::lead($id, 'lead.assign');
        $ownerId = $this->check($request, ['owner_id' => ['required', 'uuid']], ['owner_id.required' => t('crm.errors.selectOwner')])['owner_id'];
        $this->leads->assign($lead, $ownerId);

        return $this->done($request, t('crm.common.assigned'));
    }

    public function close(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $lead = CrmAccess::lead($id, 'lead.update');
        $this->leads->close($lead, $this->closeInput($request));

        return $this->done($request, t('crm.common.closed'));
    }

    public function reopen(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $lead = CrmAccess::lead($id, 'lead.update');
        $this->leads->reopen($lead);

        return $this->done($request, t('crm.common.reopened'));
    }

    public function convert(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $lead = CrmAccess::lead($id, 'lead.update');
        $data = $this->check($request, [
            'mode' => ['nullable', Rule::in(['new', 'existing'])],
            'client_id' => ['nullable', 'uuid', 'required_if:mode,existing'],
            'client_name' => ['nullable', 'string', 'max:160'],
            'client_type' => ['nullable', Rule::in(Crm::CLIENT_TYPES)],
            'title' => ['nullable', 'string', 'max:200'],
            'amount' => ['required', ...self::MONEY],
            'currency' => ['nullable', Rule::in(Crm::CURRENCIES)],
            'expected_close_date' => ['nullable', 'date_format:Y-m-d'],
        ], ['client_id.required_if' => t('leads.errors.clientRequired'), 'amount.regex' => t('crm.validation.money')], ['amount']);
        if (($data['mode'] ?? 'new') === 'new') {
            $data['client_id'] = null;
        }
        $res = $this->leads->convert($lead, $data);

        return $this->done($request, t('leads.converted'), route('crm.deals.show', $res['deal_id']), $res);
    }

    public function destroy(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $lead = CrmAccess::lead($id, 'lead.delete');
        $this->leads->remove($lead);

        return $this->done($request, t('crm.common.deleted'), route('crm.leads.index'));
    }

    /** Предупреждение о возможных дублях при вводе телефона, Instagram или email (не запрет). */
    public function duplicates(Request $request): JsonResponse
    {
        $f = $this->check($request, [
            'phone' => ['nullable', 'string', 'max:30'],
            'instagram' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'string', 'max:254'],
            'except' => ['nullable', 'uuid'],
        ]);
        $d = LeadService::duplicates($f['phone'] ?? null, $f['instagram'] ?? null, $f['email'] ?? null, $f['except'] ?? null);

        return response()->json([
            'leads' => $d['leads']->map(fn ($l) => [
                'id' => $l->id, 'number' => Format::code('L', $l->number), 'title' => $l->title,
                'status' => t('crm.status.lead.'.$l->status), 'owner' => $l->owner?->full_name, 'url' => route('crm.leads.show', $l->id),
            ])->values(),
            'clients' => $d['clients']->map(fn ($c) => [
                'id' => $c->id, 'number' => Format::code('C', $c->number), 'title' => $c->name,
                'owner' => $c->owner?->full_name, 'url' => route('crm.clients.show', $c->id),
            ])->values(),
        ]);
    }

    private function validated(Request $request, bool $create): array
    {
        $rules = [
            'title' => ['nullable', 'string', 'max:200'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'company_name' => ['nullable', 'string', 'max:160'],
            'phone' => self::PHONE,
            'telegram' => ['nullable', 'string', 'max:64'],
            'whatsapp' => self::PHONE,
            'instagram' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:254'],
            'website' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:80'],
            'source_id' => ['required', 'uuid'],
            'smm_idea_id' => ['nullable', 'uuid'],
            'service_id' => [$create ? 'required' : 'nullable', 'uuid'],
            'budget' => ['nullable', ...self::MONEY],
            'currency' => ['required', Rule::in(Crm::CURRENCIES)],
            'desired_date' => ['nullable', 'date_format:Y-m-d'],
            'priority' => ['required', Rule::in(Crm::PRIORITIES)],
            'company_size' => ['nullable', Rule::in(Crm::COMPANY_SIZES)],
            'interest' => ['nullable', 'integer', 'between:1,5'],
            'next_contact_at' => ['nullable', 'date'],
            'comment' => ['nullable', 'string', 'max:4000'],
        ];
        $messages = [
            'source_id.required' => t('leads.errors.selectSource'),
            'service_id.required' => t('leads.errors.selectService'),
            'phone.regex' => t('crm.validation.phone'),
            'whatsapp.regex' => t('crm.validation.phone'),
            'email.email' => t('crm.validation.email'),
            'budget.regex' => t('crm.validation.money'),
        ];
        if ($create) {
            $rules['owner_id'] = ['nullable', 'uuid'];
            // Минимум полей (ТЗ §78): имя или компания, телефон или Telegram
            $rules['contact_name'][0] = 'required_without:company_name';
            $rules['phone'][0] = 'required_without:telegram';
            $messages['contact_name.required_without'] = t('leads.errors.nameOrCompany');
            $messages['phone.required_without'] = t('leads.errors.phoneOrTelegram');
            array_unshift($rules['contact_name'], 'nullable');
            array_unshift($rules['phone'], 'nullable');
        }
        $data = $this->check($request, $rules, $messages, ['budget']);
        if (! $create && ! filled($data['title'] ?? null)) {
            unset($data['title']);
        }
        $data['next_contact_at'] = $this->localDateTime($data['next_contact_at'] ?? null);
        if (isset($data['email'])) {
            $data['email'] = mb_strtolower($data['email']);
        }

        return $data;
    }

    private function closeInput(Request $request): array
    {
        return $this->check($request, [
            'status' => ['required', Rule::in(Crm::CLOSE_STATUSES)],
            'loss_reason_id' => ['nullable', 'uuid'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function formOptions(?string $sourceId = null, ?string $serviceId = null, ?string $smmIdeaId = null): array
    {
        return [
            // Источник «Видео SMM» → выбор опубликованного ролика
            'smmSourceId' => LeadSource::where('code', SmmIdeaService::LEAD_SOURCE)->value('id'),
            'smmVideos' => SmmIdeaService::publishedOptions($smmIdeaId),
            'sources' => Crm::options(LeadSource::class, $sourceId),
            'services' => Crm::options(Service::class, $serviceId),
            'allSources' => Crm::allOptions(LeadSource::class),
            'allServices' => Crm::allOptions(Service::class),
        ];
    }
}
