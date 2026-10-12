<?php

namespace App\Http\Controllers\Crm;

use App\Models\Deal;
use App\Models\LeadSource;
use App\Models\Service;
use App\Services\Crm\ClientService;
use App\Services\Crm\Crm;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\TimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientController extends CrmController
{
    public function __construct(private readonly ClientService $clients) {}

    public function index(Request $request): View
    {
        $f = $this->check($request, [
            'q' => ['nullable', 'string', 'max:100'],
            'owner_id' => ['nullable', 'uuid'],
            'team_id' => ['nullable', 'uuid'],
            'type' => ['nullable', Rule::in(Crm::CLIENT_TYPES)],
        ]);
        $q = CrmAccess::clients()->with('owner')
            ->withCount(['deals', 'deals as open_deals_count' => fn ($d) => $d->where('status', 'OPEN')]);
        if ($s = $f['q'] ?? null) {
            $like = '%'.$s.'%';
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('telegram', 'like', $like)
                ->orWhereHas('contacts', fn ($c) => $c->where('full_name', 'like', $like)->orWhere('phone', 'like', $like)));
        }
        $q->when($f['owner_id'] ?? null, fn ($q, $v) => $q->where('owner_id', $v))
            ->when($f['team_id'] ?? null, fn ($q, $v) => $q->where('team_id', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v));

        return view('crm.clients.index', [
            'clients' => $q->orderByDesc('created_at')->paginate(25)->withQueryString(),
            'f' => $f,
            'sources' => Crm::options(LeadSource::class),
            'owners' => access()->can('client.create', 'TEAM') ? Crm::assignableUsers() : [],
        ]);
    }

    public function show(string $id): View
    {
        $client = CrmAccess::client($id);
        $client->load(['owner', 'team', 'source', 'contacts' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('full_name')]);
        $target = ['client_id' => $client->id];
        // Сделки клиента — только те, что видны пользователю
        $deals = access()->can('deal.read')
            ? CrmAccess::deals()->where('client_id', $client->id)->with(['owner', 'stage'])->orderByDesc('created_at')->get()
            : collect();
        $counts = Deal::where('client_id', $client->id)->selectRaw("COUNT(*) AS total, SUM(status = 'OPEN') AS open")->first();

        return view('crm.clients.show', [
            'client' => $client,
            'deals' => $deals,
            'dealsCount' => (int) $counts->total,
            'openDealsCount' => (int) $counts->open,
            'activities' => TimelineService::activities($target),
            'comments' => TimelineService::comments($target),
            'sources' => Crm::options(LeadSource::class, $client->source_id),
            'serviceOptions' => Crm::options(Service::class),
            'requisites' => is_array($client->requisites) ? $client->requisites : null,
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $this->validated($request) + $this->check($request, [
            'owner_id' => ['nullable', 'uuid'],
            'contact.full_name' => ['nullable', 'string', 'max:120'],
            'contact.phone' => self::PHONE,
        ], ['contact.phone.regex' => t('crm.validation.phone')]);
        $client = $this->clients->create($data);

        return $this->done($request, t('crm.common.created'), route('crm.clients.show', $client->id), ['id' => $client->id]);
    }

    public function update(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $client = CrmAccess::client($id, 'client.update');
        $this->clients->update($client, $this->validated($request));

        return $this->done($request, t('crm.common.saved'));
    }

    public function storeContact(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $client = CrmAccess::client($id, 'client.update');
        $this->clients->addContact($client, $this->contactInput($request));

        return $this->done($request, t('crm.common.created'));
    }

    public function updateContact(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $contact = ClientService::contact($id);
        $this->clients->updateContact($contact, $this->contactInput($request));

        return $this->done($request, t('crm.common.saved'));
    }

    public function destroyContact(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $this->clients->removeContact(ClientService::contact($id));

        return $this->done($request, t('crm.common.deleted'));
    }

    /** Реквизиты для договора (ИНН — 9 цифр, ПИНФЛ — 14, МФО — 5, счёт — 20). */
    public function requisites(Request $request, string $id): RedirectResponse|JsonResponse
    {
        $client = CrmAccess::client($id, 'client.update');
        $text = fn (int $max) => ['nullable', 'string', 'max:'.$max];
        $data = $this->check($request, [
            'legalName' => $text(300), 'inn' => ['nullable', 'regex:/^(\d{9}|\d{14})$/'], 'director' => $text(120),
            'directorPosition' => $text(120), 'signerGenitive' => $text(200), 'basis' => $text(200), 'address' => $text(300),
            'phone' => $text(60), 'bank' => $text(200), 'mfo' => ['nullable', 'regex:/^\d{5}$/'],
            'account' => ['nullable', 'regex:/^\d{20}$/'], 'oked' => $text(10), 'vatCode' => $text(30),
        ], [
            'inn.regex' => t('clients.errors.inn'),
            'mfo.regex' => t('clients.errors.mfo'),
            'account.regex' => t('clients.errors.account'),
        ]);
        $this->clients->saveRequisites($client, $data);

        return $this->done($request, t('requisites.saved'));
    }

    private function validated(Request $request): array
    {
        $data = $this->check($request, [
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(Crm::CLIENT_TYPES)],
            'industry' => ['nullable', 'string', 'max:120'],
            'phone' => self::PHONE,
            'email' => ['nullable', 'email', 'max:254'],
            'telegram' => ['nullable', 'string', 'max:64'],
            'website' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:80'],
            'source_id' => ['nullable', 'uuid', Rule::exists('lead_sources', 'id')],
            'comment' => ['nullable', 'string', 'max:4000'],
        ], [
            'name.required' => t('clients.errors.nameRequired'),
            'phone.regex' => t('crm.validation.phone'),
            'email.email' => t('crm.validation.email'),
        ]);
        foreach (['industry', 'phone', 'email', 'telegram', 'website', 'city', 'country', 'source_id', 'comment'] as $k) {
            $data[$k] ??= null;
        }
        if ($data['email']) {
            $data['email'] = mb_strtolower($data['email']);
        }

        return $data;
    }

    private function contactInput(Request $request): array
    {
        $data = $this->check($request, [
            'full_name' => ['required', 'string', 'max:120'],
            'position' => ['nullable', 'string', 'max:120'],
            'phone' => self::PHONE,
            'telegram' => ['nullable', 'string', 'max:64'],
            'whatsapp' => self::PHONE,
            'instagram' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:254'],
        ], [
            'full_name.required' => t('clients.errors.contactName'),
            'phone.regex' => t('crm.validation.phone'),
            'whatsapp.regex' => t('crm.validation.phone'),
            'email.email' => t('crm.validation.email'),
        ]);
        foreach (['position', 'phone', 'telegram', 'whatsapp', 'instagram', 'email'] as $k) {
            $data[$k] ??= null;
        }
        $data['is_primary'] = $request->boolean('is_primary');

        return $data;
    }
}
