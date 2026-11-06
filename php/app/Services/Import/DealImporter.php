<?php

namespace App\Services\Import;

use App\Models\Client;
use App\Models\DealStage;
use App\Models\Service;
use App\Services\Crm\ClientService;
use App\Services\Crm\Crm;
use App\Services\Crm\DealService;
use App\Services\Crm\DealStages;
use App\Services\Crm\TimelineService;
use App\Services\References\ExchangeRates;
use App\Support\Format;
use App\Support\Lang;

/**
 * Сделки: создаются через DealService::create (статус «В работе», этап «Потребность определена»,
 * курс фиксируется, история этапов, таймлайн, аудит, deal.created). Указанный открытый этап
 * ставится сразу после создания с записью в историю этапов. «Оплачено»/выигранные не импортируются —
 * сделка становится оплаченной только подтверждением оплаты. Клиент — по номеру или точному названию;
 * если не найден и отмечено «Создавать клиентов» — создаётся через ClientService.
 */
final class DealImporter extends Importer
{
    /** Этапы, которые можно указать в файле (все открытые, кроме «Оплачено»). */
    public const STAGES = ['NEED_DEFINED', 'PROPOSAL_SENT', 'NEGOTIATION', 'CONTRACT', 'AWAITING_PAYMENT'];

    public static function entity(): string
    {
        return 'deals';
    }

    public static function permission(): string
    {
        return 'deal.create';
    }

    public static function backUrl(): string
    {
        return '/sales/deals';
    }

    protected function columns(): array
    {
        return [
            'title' => ['required' => true],
            'client' => ['required' => true],
            'amount' => ['type' => 'money', 'required' => true],
            'currency' => [],
            'stage' => [],
            'service' => [],
            'expected_close_date' => ['type' => 'date'],
            'owner_email' => ['type' => 'email'],
            'comment' => [],
        ];
    }

    public function options(): array
    {
        return ['create_clients'];
    }

    public function rules(): array
    {
        return [t('import.rules.dealClient'), t('import.rules.dealStage'), t('import.rules.usdRate'), t('import.rules.owner')];
    }

    protected function example(): array
    {
        return [
            'title' => t('import.example.dealTitle'), 'client' => t('import.example.company'), 'amount' => '15000000',
            'currency' => 'UZS', 'stage' => 'NEED_DEFINED', 'service' => 'WEBSITE',
            'expected_close_date' => Format::today()->addDays(30)->toDateString(), 'owner_email' => '', 'comment' => t('import.example.comment'),
        ];
    }

    protected function reference(): array
    {
        return [
            $this->col('stage') => self::refRows(DealStage::whereIn('code', self::STAGES)->orderBy('sort')->get()),
            $this->col('service') => self::refRows(Service::where('is_active', true)->orderBy('sort')->get()),
            $this->col('currency') => array_map(fn ($c) => [$c, $c], Crm::CURRENCIES),
            $this->col('owner_email') => self::assignable('deal.create'),
        ];
    }

    protected function label(array $v): string
    {
        return trim(($v['title'] ?? '').' · '.($v['client'] ?? ''), ' ·');
    }

    protected function check(array $v, ImportContext $ctx): array
    {
        $data = [
            'title' => Values::text($v['title'], 200, $this->col('title')),
            'amount' => Values::money($v['amount'], $this->col('amount')),
            'currency' => self::currency($v['currency'], $this->col('currency')),
            'expected_close_date' => Values::date($v['expected_close_date'], $this->col('expected_close_date')),
            'service_id' => Values::ref($v['service'], $this->services($ctx), $this->col('service'))?->id,
            'comment' => Values::text($v['comment'], 4000, $this->col('comment')),
            'stage' => $this->stage($v['stage'], $ctx),
        ];
        // USD без курса — ошибка строки (сумма в UZS фиксируется по курсу на сегодня)
        ExchangeRates::convert($data['amount'], $data['currency']);
        $owner = $this->owner($v['owner_email'], 'deal.create');
        $data['owner_id'] = $owner?->id;
        [$data['client_id'], $data['client_name'], $reason] = $this->clientFor($v['client'], $owner?->id, $ctx);

        return ['status' => 'create', 'data' => $data, 'reason' => $reason];
    }

    /**
     * Клиент сделки: найденный (id) или новый (название), с пояснением для предпросмотра.
     *
     * @return array{0:?string, 1:?string, 2:?string}
     */
    protected function clientFor(string $value, ?string $ownerId, ImportContext $ctx): array
    {
        $client = $this->client($value, $ctx->option('create_clients'));
        if ($client) {
            return [$client->id, null, null];
        }
        if (! access()->can('client.create')) {
            throw new RowError(t('import.errors.noClientRight'));
        }
        $name = Values::text($value, 160, $this->col('client'));
        $key = mb_strtolower($name);
        $first = $ctx->cache['newClients'][$key] ?? null;
        $ctx->cache['newClients'][$key] ??= true;

        return [null, $name, $first ? t('import.reasons.clientFromEarlierRow') : t('import.reasons.clientWillBeCreated', ['name' => $name])];
    }

    /** Создаёт клиента строки (если нужен) — внутри транзакции строки. */
    protected function ensureClient(array $data): Client
    {
        if ($data['client_id']) {
            return Client::findOrFail($data['client_id']);
        }
        // Клиент мог быть создан предыдущей строкой этого же файла
        $existing = $this->client($data['client_name'], true);
        if ($existing) {
            return $existing;
        }

        return app(ClientService::class)->create(['name' => $data['client_name'], 'type' => 'COMPANY', 'owner_id' => $data['owner_id']]);
    }

    public static function currency(?string $v, string $column): string
    {
        return Values::enum($v, Crm::CURRENCIES, 'import.currency', $column, ['сум' => 'UZS', 'so\'m' => 'UZS', 'sum' => 'UZS', '$' => 'USD', 'доллар' => 'USD', 'dollar' => 'USD']) ?? 'UZS';
    }

    private function stage(?string $value, ImportContext $ctx): string
    {
        if ($value === null) {
            return 'NEED_DEFINED';
        }
        $all = $ctx->cached('dealStages', fn () => DealStage::where('entity', 'DEAL')->orderBy('sort')->get());
        try {
            $stage = Values::ref($value, $all, $this->col('stage'));
        } catch (RowError $e) {
            // «Выиграна»/«WON» — не этап, а статус оплаченной сделки
            if (Values::norm($value) === 'won' || in_array(Values::norm($value), array_map(fn ($l) => Values::norm(Lang::get('crm.status.deal.WON', [], $l)), Lang::LOCALES), true)) {
                throw new RowError(t('import.errors.stagePaid'));
            }
            throw $e;
        }
        if ($stage->code === 'PAID') {
            throw new RowError(t('import.errors.stagePaid'));
        }

        return $stage->code;
    }

    protected function create(array $data, ImportContext $ctx): string
    {
        $client = $this->ensureClient($data);
        $deal = app(DealService::class)->create([
            'title' => $data['title'],
            'client_id' => $client->id,
            'owner_id' => $data['owner_id'],
            'service_id' => $data['service_id'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'expected_close_date' => $data['expected_close_date'],
        ]);
        if ($data['stage'] !== 'NEED_DEFINED') {
            // Перенос сделки из прежнего учёта: этап ставится без «ворот» документов, переход пишется в историю
            DealStages::move($deal, $data['stage'], access()->id(), audit: true, auto: true);
        }
        if ($data['comment']) {
            app(TimelineService::class)->addComment(['deal_id' => $deal->id], $data['comment']);
        }

        return Format::code('D', $deal->number);
    }
}
