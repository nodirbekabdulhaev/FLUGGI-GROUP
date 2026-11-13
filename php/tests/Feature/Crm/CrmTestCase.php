<?php

namespace Tests\Feature\Crm;

use App\Models\ExchangeRate;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LossReason;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Support\Format;
use Tests\TestCase;

/**
 * Фикстуры CRM как в прежней версии: отдел 1 (РОП + менеджер), отдел 2 (второй менеджер),
 * CEO, HR, исполнитель; курс USD 12 650.
 */
abstract class CrmTestCase extends TestCase
{
    protected User $ceo;

    protected User $rop;

    protected User $manager;

    protected User $otherManager;

    protected User $hr;

    protected User $executor;

    protected Team $team1;

    protected Team $team2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ceo = $this->user('CEO', attrs: ['full_name' => 'Ceo Boss']);
        $this->rop = $this->user('ROP', attrs: ['full_name' => 'Rop Head']);
        $this->team1 = $this->team($this->rop, 'Отдел 1');
        $this->rop->update(['team_id' => $this->team1->id]);
        $this->manager = $this->user('MANAGER', $this->team1, ['full_name' => 'Manager One']);
        $this->team2 = $this->team(null, 'Отдел 2');
        $this->otherManager = $this->user('MANAGER', $this->team2, ['full_name' => 'Manager Two']);
        $this->hr = $this->user('HR_ADMIN');
        $this->executor = $this->user('EXECUTOR');
        ExchangeRate::create(['date' => Format::today()->subDay()->toDateString(), 'currency' => 'USD', 'rate_to_uzs' => '12650']);
    }

    protected function ref(string $model, string $code): string
    {
        return $model::where('code', $code)->value('id');
    }

    protected function leadData(array $extra = []): array
    {
        return $extra + [
            'contact_name' => 'Hamza',
            'company_name' => 'Hamza Textile',
            'phone' => '+998901234567',
            'source_id' => $this->ref(LeadSource::class, 'INSTAGRAM'),
            'service_id' => $this->ref(Service::class, 'SMM'),
            'budget' => '9000000',
            'currency' => 'UZS',
            'priority' => 'MEDIUM',
        ];
    }

    protected function newLead(User $as, array $extra = []): Lead
    {
        $this->actingAsUser($as)->post('/sales/leads', $this->leadData($extra))->assertSessionHasNoErrors()->assertRedirect();

        return Lead::latest('number')->firstOrFail();
    }

    protected function lossReason(string $code): string
    {
        return $this->ref(LossReason::class, $code);
    }

    /** Конвертация лида (РОП) — возвращает [clientId, dealId]. */
    protected function convert(Lead $lead, User $as, array $data = []): array
    {
        $this->actingAsUser($as)->post("/sales/leads/{$lead->id}/convert", $data + ['client_name' => 'Hamza Textile', 'amount' => '9000000', 'currency' => 'UZS'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $lead->refresh();

        return [$lead->client_id, $lead->deal_id];
    }
}
