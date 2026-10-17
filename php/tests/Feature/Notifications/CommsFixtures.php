<?php

namespace Tests\Feature\Notifications;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\Outbox;

/**
 * Данные для тестов модуля «Коммуникации»: два отдела, CEO, РОП, менеджеры, исполнитель.
 * Записи CRM создаются напрямую (модули CRM/продаж тестируются отдельно).
 */
trait CommsFixtures
{
    protected User $ceo;

    protected User $rop;

    protected User $manager;

    protected User $otherManager;

    protected User $otherRop;

    protected User $executor;

    protected Team $team1;

    protected Team $team2;

    protected function fixtures(): void
    {
        $this->ceo = $this->user('CEO', attrs: ['email' => 'ceo@test.uz']);
        $this->rop = $this->user('ROP', attrs: ['email' => 'rop@test.uz']);
        $this->team1 = $this->team($this->rop, 'Отдел 1');
        $this->rop->update(['team_id' => $this->team1->id]);
        $this->otherRop = $this->user('ROP', attrs: ['email' => 'rop2@test.uz']);
        $this->team2 = $this->team($this->otherRop, 'Отдел 2');
        $this->otherRop->update(['team_id' => $this->team2->id]);
        $this->manager = $this->user('MANAGER', $this->team1, ['email' => 'manager@test.uz']);
        $this->otherManager = $this->user('MANAGER', $this->team2, ['email' => 'manager2@test.uz']);
        $this->executor = $this->user('EXECUTOR', attrs: ['email' => 'executor@test.uz']);
    }

    protected function drain(): void
    {
        while (Outbox::processBatch() > 0);
    }

    protected function lead(User $owner, array $attrs = []): Lead
    {
        $lead = Lead::create($attrs + [
            'title' => 'Лид '.uniqid(),
            'source_id' => LeadSource::where('code', 'OTHER')->value('id'),
            'owner_id' => $owner->id,
            'team_id' => $owner->team_id,
            'stage_id' => DealStage::where('code', 'NEW')->value('id'),
            'created_by_id' => $owner->id,
        ]);

        return $lead->refresh();
    }

    protected function client(User $owner, array $attrs = []): Client
    {
        return Client::create($attrs + ['name' => 'Клиент '.uniqid(), 'owner_id' => $owner->id, 'team_id' => $owner->team_id])->refresh();
    }

    protected function deal(Client $client, User $owner, string $amount = '5000000', array $attrs = []): Deal
    {
        return Deal::create($attrs + [
            'title' => 'Сделка '.uniqid(), 'client_id' => $client->id, 'owner_id' => $owner->id, 'team_id' => $owner->team_id,
            'amount' => $amount, 'amount_uzs' => $amount, 'stage_id' => DealStage::where('code', 'NEED_DEFINED')->value('id'),
            'created_by_id' => $owner->id,
        ])->refresh();
    }

    /** Подписанный договор и подтверждённая оплата — «продажа». */
    protected function sale(User $owner, string $name = 'Alfa', string $amount = '5000000'): array
    {
        $client = $this->client($owner, ['name' => $name, 'phone' => '+998901234500']);
        $lead = $this->lead($owner, ['title' => $name.' лид', 'contact_name' => $name, 'phone' => '+998901234500', 'client_id' => $client->id]);
        $deal = $this->deal($client, $owner, $amount, ['title' => $name.' SMM', 'status' => 'WON', 'won_at' => now()]);
        $lead->update(['deal_id' => $deal->id, 'converted_at' => now(), 'status' => 'CONVERTED']);
        Contract::create(['deal_id' => $deal->id, 'client_id' => $client->id, 'contract_date' => now()->toDateString(), 'amount' => $amount,
            'amount_uzs' => $amount, 'status' => 'SIGNED', 'signed_at' => now(), 'created_by_id' => $owner->id]);
        Payment::create(['deal_id' => $deal->id, 'client_id' => $client->id, 'amount' => $amount, 'amount_uzs' => $amount, 'type' => 'FULL',
            'method' => 'BANK', 'status' => 'PAID', 'paid_at' => now(), 'created_by_id' => $owner->id]);
        $project = Project::create(['name' => $name.' — SMM', 'client_id' => $client->id, 'deal_id' => $deal->id, 'price' => $amount, 'price_uzs' => $amount,
            'rop_id' => $this->rop->id, 'manager_id' => $owner->id, 'team_id' => $owner->team_id, 'status' => 'IN_PROGRESS'])->refresh();

        return compact('client', 'lead', 'deal', 'project');
    }
}
