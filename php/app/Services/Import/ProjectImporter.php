<?php

namespace App\Services\Import;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Direction;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Services\Crm\Activities;
use App\Services\Crm\ClientService;
use App\Services\Crm\Crm;
use App\Services\Crm\CrmAccess;
use App\Services\Crm\DealService;
use App\Services\Crm\DealStages;
use App\Services\Crm\TimelineService;
use App\Services\Projects\ProjectAccess;
use App\Services\Projects\Templates;
use App\Services\References\ExchangeRates;
use App\Support\Audit;
use App\Support\Format;
use App\Support\Outbox;

/**
 * Проекты. Обычно проект появляется после первой оплаты сделки (PaymentsService::ensureProject);
 * при переносе из прежнего учёта проект привязывается к выигранной сделке (D-00001) или, если сделки
 * нет, создаётся сделка «Выиграна» на стоимость проекта — без оплат (деньги не импортируются).
 * Один проект на сделку. Задачи по шаблону услуги — только с флажком «Создать задачи по шаблону».
 */
final class ProjectImporter extends Importer
{
    public static function entity(): string
    {
        return 'projects';
    }

    public static function permission(): string
    {
        return 'project.create';
    }

    public static function backUrl(): string
    {
        return '/projects/all';
    }

    protected function columns(): array
    {
        return [
            'name' => ['required' => true],
            'client' => ['required' => true],
            'deal' => [],
            'rop_email' => ['type' => 'email', 'required' => true],
            'manager_email' => ['type' => 'email'],
            'direction' => [],
            'service' => [],
            'price' => ['type' => 'money'],
            'currency' => [],
            'start_date' => ['type' => 'date'],
            'deadline' => ['type' => 'date'],
            'project_status' => [],
            'description' => [],
        ];
    }

    public function options(): array
    {
        return ['create_clients', 'create_tasks'];
    }

    public function rules(): array
    {
        return [t('import.rules.projectDeal'), t('import.rules.projectNoPayments'), t('import.rules.projectRop'), t('import.rules.projectTasks'), t('import.rules.usdRate')];
    }

    protected function example(): array
    {
        return [
            'name' => t('import.example.projectName'), 'client' => t('import.example.company'), 'deal' => '',
            'rop_email' => access()->roleCode === 'ROP' ? access()->user->email : '', 'manager_email' => '', 'direction' => 'IT', 'service' => 'WEBSITE',
            'price' => '25000000', 'currency' => 'UZS', 'start_date' => Format::today()->toDateString(),
            'deadline' => Format::today()->addDays(45)->toDateString(), 'project_status' => 'IN_PROGRESS', 'description' => t('import.example.comment'),
        ];
    }

    protected function reference(): array
    {
        $rops = User::with('role')->where('status', 'ACTIVE')->whereHas('role', fn ($q) => $q->whereIn('code', ['ROP', 'CEO']))
            ->when(access()->scope('project.create') !== 'ALL', fn ($q) => $q->whereKey(access()->id()))->orderBy('full_name')->get();

        return [
            $this->col('rop_email') => self::people($rops),
            $this->col('manager_email') => self::assignable('project.create'),
            $this->col('direction') => array_map(fn ($d) => [$d->code, $d->name], Direction::where('is_active', true)->orderBy('sort')->get()->all()),
            $this->col('service') => self::refRows(Service::where('is_active', true)->orderBy('sort')->get()),
            $this->col('project_status') => self::enumRows(ProjectAccess::STATUSES, 'sales.projectStatus'),
            $this->col('currency') => array_map(fn ($c) => [$c, $c], Crm::CURRENCIES),
        ];
    }

    protected function label(array $v): string
    {
        return trim(($v['name'] ?? '').' · '.($v['client'] ?? ''), ' ·');
    }

    protected function check(array $v, ImportContext $ctx): array
    {
        $access = access();
        $data = [
            'name' => Values::text($v['name'], 300, $this->col('name')),
            'description' => Values::text($v['description'], 4000, $this->col('description')),
            'status' => Values::enum($v['project_status'], ProjectAccess::STATUSES, 'sales.projectStatus', $this->col('project_status')) ?? 'NEW',
            'start_date' => Values::date($v['start_date'], $this->col('start_date')),
            'deadline' => Values::date($v['deadline'], $this->col('deadline')),
            'price' => Values::money($v['price'], $this->col('price')),
            'currency' => DealImporter::currency($v['currency'], $this->col('currency')),
            'direction_id' => Values::ref($v['direction'], $ctx->cached('directions', fn () => Direction::where('is_active', true)->get()), $this->col('direction'))?->id,
            'service_id' => Values::ref($v['service'], $this->services($ctx), $this->col('service'))?->id,
            'create_tasks' => $ctx->option('create_tasks'),
        ];
        if ($data['start_date'] && $data['deadline'] && $data['start_date'] > $data['deadline']) {
            throw new RowError(t('projects.errors.deadlineBeforeStart'));
        }
        // РОП проекта — РОП или CEO; назначить другого РОП может только тот, кто видит все проекты (как при смене РОП)
        $rop = $this->userByEmail($v['rop_email'], 'rop_email');
        if (! in_array($rop->role?->code, ['ROP', 'CEO'], true)) {
            throw new RowError(t('projects.errors.ropRole'));
        }
        if ($rop->id !== $access->id() && $access->scope('project.create') !== 'ALL') {
            throw new RowError(t('import.errors.ropScope', ['email' => $rop->email]));
        }
        $data['rop_id'] = $rop->id;
        $manager = $this->owner($v['manager_email'], 'project.create', 'manager_email');

        $client = $this->client($v['client'], $ctx->option('create_clients'));
        $reasons = [];
        if (! $client) {
            if (! $access->can('client.create')) {
                throw new RowError(t('import.errors.noClientRight'));
            }
            $data['client_name'] = Values::text($v['client'], 160, $this->col('client'));
            $reasons[] = t('import.reasons.clientWillBeCreated', ['name' => $data['client_name']]);
        }
        $data['client_id'] = $client?->id;

        if ($v['deal'] !== null) {
            $number = Values::number($v['deal'], 'D');
            $deal = $number !== null ? CrmAccess::deals()->where('number', $number)->first() : null;
            if (! $deal) {
                throw new RowError(t('import.errors.dealNotFound', ['value' => $v['deal']]));
            }
            if ($deal->client_id !== $client?->id) {
                throw new RowError(t('import.errors.dealOtherClient', ['deal' => Format::code('D', $deal->number)]));
            }
            if ($deal->status !== 'WON') {
                throw new RowError(t('import.errors.dealNotWon', ['deal' => Format::code('D', $deal->number)]));
            }
            $existing = Project::withTrashed()->where('deal_id', $deal->id)->first();
            if ($existing) {
                return ['status' => 'duplicate', 'reason' => t('import.reasons.dealHasProject', ['deal' => Format::code('D', $deal->number), 'project' => Format::code('P', $existing->number)])];
            }
            $data['deal_id'] = $deal->id;
            $data['manager_id'] = $manager?->id ?? $deal->owner_id;
            $data['service_id'] ??= $deal->service_id;
            if ($data['price'] !== null) {
                ExchangeRates::convert($data['price'], $data['currency']);
            }
            $keys = ['deal:'.$deal->id];
        } else {
            if (! $access->can('deal.create')) {
                throw new RowError(t('import.errors.noDealRight'));
            }
            if ($data['price'] === null) {
                throw new RowError(t('import.errors.priceRequired'));
            }
            ExchangeRates::convert($data['price'], $data['currency']);
            if ($client) {
                $same = Project::where('client_id', $client->id)->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($data['name'])])->first();
                if ($same) {
                    return ['status' => 'duplicate', 'reason' => t('import.reasons.projectExists', ['project' => Format::code('P', $same->number)])];
                }
            }
            // Менеджер сделки: указанный, иначе ответственный клиента (как при создании сделки)
            $managerId = $manager?->id ?? $client?->owner_id;
            if (! $managerId || ! ($m = User::with('role')->find($managerId)) || ! in_array($m->role?->code, CrmAccess::OWNER_ROLES, true) || ! self::inScope($m, 'deal.create')) {
                if ($v['manager_email'] === null && in_array($access->roleCode, CrmAccess::OWNER_ROLES, true)) {
                    $managerId = $access->id();
                } elseif ($v['manager_email'] === null) {
                    $managerId = CrmAccess::leastLoadedOwner();
                } else {
                    throw new RowError(t('import.errors.ownerScope', ['email' => (string) $v['manager_email']]));
                }
            }
            $data['deal_id'] = null;
            $data['manager_id'] = $managerId;
            $reasons[] = t('import.reasons.dealWillBeCreated');
            $keys = ['project:'.mb_strtolower(($client?->id ?? $data['client_name']).'|'.$data['name'])];
        }

        return ['status' => 'create', 'data' => $data, 'keys' => $keys, 'reason' => $reasons ? implode(' ', $reasons) : null];
    }

    protected function create(array $data, ImportContext $ctx): string
    {
        $actor = access()->id();
        $client = $data['client_id'] ? Client::findOrFail($data['client_id'])
            : ($this->client($data['client_name'], true)
                ?? app(ClientService::class)->create(['name' => $data['client_name'], 'type' => 'COMPANY', 'owner_id' => $data['manager_id']]));

        if ($data['deal_id']) {
            $deal = Deal::whereKey($data['deal_id'])->lockForUpdate()->firstOrFail();
            if (Project::withTrashed()->where('deal_id', $deal->id)->exists()) {
                throw new RowError(t('import.reasons.dealHasProject', ['deal' => Format::code('D', $deal->number), 'project' => '—']));
            }
        } else {
            $deal = $this->wonDeal($data, $client->id, $actor);
        }

        if ($data['price'] !== null) {
            ['amountUzs' => $priceUzs] = ExchangeRates::convert($data['price'], $data['currency']);
            [$price, $currency] = [$data['price'], $data['currency']];
        } else {
            [$price, $currency, $priceUzs] = [(string) $deal->amount, $deal->currency, (string) $deal->amount_uzs];
        }
        $directionId = $data['direction_id'] ?? ($deal->service_id ? Service::whereKey($deal->service_id)->value('direction_id') : null);
        $completedAt = $data['status'] === 'COMPLETED' ? ($data['deadline'] ? Crm::dayStart($data['deadline']) : now()) : null;
        $project = Project::create([
            'direction_id' => $directionId,
            'name' => $data['name'],
            'client_id' => $client->id,
            'deal_id' => $deal->id,
            'price' => $price,
            'currency' => $currency,
            'price_uzs' => $priceUzs,
            'rop_id' => $data['rop_id'],
            'manager_id' => $data['manager_id'],
            'team_id' => $deal->team_id,
            'status' => $data['status'],
            'start_date' => $data['start_date'] ?? Format::today()->toDateString(),
            'deadline' => $data['deadline'],
            'description' => $data['description'],
            'completed_at' => $completedAt,
        ])->refresh();
        $code = Format::code('P', $project->number);
        Activities::log('project.created', ['deal_id' => $deal->id, 'client_id' => $client->id, 'project_id' => $project->id], [
            'number' => $code, 'name' => $project->name, 'imported' => true,
        ], $actor);
        Audit::log('project.create', 'project', $project->id, [
            'name' => ['old' => null, 'new' => $project->name],
            'deal_id' => ['old' => null, 'new' => $deal->id],
            'status' => ['old' => null, 'new' => $project->status],
            'source' => ['old' => null, 'new' => 'import'],
        ], $actor);
        if ($data['create_tasks'] && in_array($project->status, ProjectAccess::ACTIVE, true)) {
            $template = Templates::forService($data['service_id'] ?? $deal->service_id);
            if ($template) {
                Templates::apply($project, $template->id, $actor);
            }
        }
        Outbox::publish('project.created', ['projectId' => $project->id, 'dealId' => $deal->id, 'ropId' => $project->rop_id, 'managerId' => $project->manager_id], $actor);

        return $code;
    }

    /**
     * Сделка проекта без сделки в файле: создаётся как обычно (DealService), переводится на этап
     * «Оплачено» и в статус «Выиграна» с пометкой об импорте. Оплаты не создаются.
     */
    private function wonDeal(array $data, string $clientId, string $actor): Deal
    {
        $deal = app(DealService::class)->create([
            'title' => $data['name'],
            'client_id' => $clientId,
            'owner_id' => $data['manager_id'],
            'service_id' => $data['service_id'],
            'amount' => $data['price'],
            'currency' => $data['currency'],
            'expected_close_date' => null,
        ]);
        DealStages::move($deal, 'PAID', $actor, audit: true, auto: true);
        $wonAt = $data['start_date'] ? Crm::dayStart($data['start_date']) : now();
        $deal->update(['status' => 'WON', 'won_at' => $wonAt, 'closed_at' => $wonAt]);
        Activities::log('deal.imported_won', ['deal_id' => $deal->id, 'client_id' => $clientId], ['project' => $data['name']], $actor);
        Audit::log('deal.import_won', 'deal', $deal->id, [
            'status' => ['old' => 'OPEN', 'new' => 'WON'],
            'source' => ['old' => null, 'new' => 'import'],
        ], $actor);
        app(TimelineService::class)->addComment(['deal_id' => $deal->id], t('import.projects.dealComment'));

        return $deal->refresh();
    }
}
