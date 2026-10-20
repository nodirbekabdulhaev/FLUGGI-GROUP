<?php

namespace Tests\Feature\Sales;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\ExchangeRate;
use App\Models\Proposal;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Support\Format;
use Tests\TestCase;

/**
 * Фикстура продаж (как resetDatabase прежней версии): CEO, отдел 1 (РОП + менеджер),
 * отдел 2 (менеджер2), исполнитель; курс USD 12 650; сделка на 9 000 000 UZS у менеджера.
 */
abstract class SalesTestCase extends TestCase
{
    protected User $ceo;

    protected User $rop;

    protected User $manager;

    protected User $manager2;

    protected User $executor;

    protected Team $team1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ceo = $this->user('CEO');
        $this->rop = $this->user('ROP');
        $this->team1 = $this->team($this->rop);
        $this->rop->update(['team_id' => $this->team1->id]);
        $this->manager = $this->user('MANAGER', $this->team1);
        $rop2 = $this->user('ROP');
        $team2 = $this->team($rop2);
        $this->manager2 = $this->user('MANAGER', $team2);
        $this->executor = $this->user('EXECUTOR');
        ExchangeRate::create(['date' => Format::today()->subDays(3)->toDateString(), 'currency' => 'USD', 'rate_to_uzs' => '12650']);
    }

    /** Сделка этапа «Потребность определена» у менеджера отдела 1. */
    protected function deal(string $amount = '9000000', ?User $owner = null, string $serviceCode = 'SMM'): Deal
    {
        $owner ??= $this->manager;
        $client = Client::create(['name' => 'Hamza Textile', 'owner_id' => $owner->id, 'team_id' => $owner->team_id, 'phone' => '+998901234567']);

        return Deal::create([
            'title' => 'Hamza Textile — SMM',
            'client_id' => $client->id,
            'owner_id' => $owner->id,
            'team_id' => $owner->team_id,
            'service_id' => Service::where('code', $serviceCode)->value('id'),
            'amount' => $amount,
            'currency' => 'UZS',
            'exchange_rate' => '1',
            'amount_uzs' => $amount,
            'stage_id' => DealStage::where('code', 'NEED_DEFINED')->value('id'),
            'created_by_id' => $owner->id,
        ])->refresh();
    }

    protected function stageOf(Deal $deal): string
    {
        return $deal->fresh()->stage->code;
    }

    /** КП → отправлено → принято → договор → подписан. КП и договор оформляет РОП отдела сделки. */
    protected function readyToPay(Deal $deal, string $amount = '9000000', ?User $by = null): array
    {
        $this->actingAsUser($by ?? $this->rop);
        $this->post('/sales/proposals', ['deal_id' => $deal->id, 'title' => 'SMM', 'currency' => 'UZS',
            'items' => [['description' => 'SMM', 'quantity' => '1', 'unit_price' => $amount]]])->assertSessionHasNoErrors();
        $p = Proposal::where('deal_id', $deal->id)->latest('created_at')->first();
        $this->post("/sales/proposals/{$p->id}/send")->assertSessionHasNoErrors();
        $this->post("/sales/proposals/{$p->id}/accept")->assertSessionHasNoErrors();
        $this->post('/sales/contracts', ['deal_id' => $deal->id, 'proposal_id' => $p->id, 'contract_date' => '2026-10-06', 'amount' => $amount, 'currency' => 'UZS'])->assertSessionHasNoErrors();
        $c = Contract::where('deal_id', $deal->id)->first();
        $this->post("/sales/contracts/{$c->id}/sign")->assertSessionHasNoErrors();

        return [$p, $c];
    }
}
