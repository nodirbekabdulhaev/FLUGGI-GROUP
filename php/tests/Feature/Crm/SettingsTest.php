<?php

namespace Tests\Feature\Crm;

use App\Models\AuditLog;
use App\Models\DealStage;
use App\Models\Direction;
use App\Models\ExchangeRate;
use App\Models\LossReason;
use App\Models\Service;
use App\Models\Team;
use App\Support\Format;

/** Справочники CRM и отделы продаж. */
class SettingsTest extends CrmTestCase
{
    public function test_references_crud_and_rights(): void
    {
        $this->actingAsUser($this->manager)->get('/settings/references')->assertForbidden();
        $this->actingAsUser($this->manager)->post('/settings/references/services', ['code' => 'X'])->assertForbidden();
        $this->actingAsUser($this->hr)->post('/settings/references/services', [
            'code' => 'AUDIT_SVC', 'name' => 'Аудит', 'name_uz' => 'Audit', 'base_price' => '1 000 000', 'currency' => 'UZS', 'pricing_type' => 'FIXED', 'is_active' => '1',
        ])->assertSessionHasNoErrors();
        $svc = Service::where('code', 'AUDIT_SVC')->firstOrFail();
        $this->assertSame('1000000.00', (string) $svc->base_price);
        $this->actingAsUser($this->hr)->post('/settings/references/services', ['code' => 'bad code', 'name' => 'x', 'currency' => 'UZS', 'pricing_type' => 'FIXED'])->assertSessionHasErrors('code');
        $this->actingAsUser($this->hr)->post('/settings/references/services', ['code' => 'AUDIT_SVC', 'name' => 'x', 'currency' => 'UZS', 'pricing_type' => 'FIXED'])->assertSessionHasErrors('code');
        $this->actingAsUser($this->hr)->put("/settings/references/services/{$svc->id}", ['code' => 'AUDIT_SVC', 'name' => 'Аудит 2', 'currency' => 'UZS', 'pricing_type' => 'MONTHLY'])->assertSessionHasNoErrors();
        $this->assertFalse($svc->fresh()->is_active);

        $this->actingAsUser($this->hr)->post('/settings/references/loss-reasons', ['code' => 'WEATHER', 'name' => 'Погода', 'is_active' => '1', 'requires_comment' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(LossReason::where('code', 'WEATHER')->value('requires_comment'));
        $this->actingAsUser($this->hr)->post('/settings/references/bogus', ['code' => 'A1', 'name' => 'x'])->assertNotFound();

        $stage = DealStage::where('code', 'NEGOTIATION')->firstOrFail();
        $this->actingAsUser($this->hr)->put("/settings/references/stages/{$stage->id}", ['name' => 'Торг', 'probability' => 60])->assertSessionHasNoErrors();
        $this->assertSame(60, $stage->fresh()->probability);
        $this->assertTrue(AuditLog::where('action', 'stage.update')->exists());

        $this->actingAsUser($this->hr)->post('/settings/references/directions', ['name' => 'AI', 'is_active' => '1'])->assertSessionHasNoErrors();
        $this->assertStringStartsWith('D_', Direction::where('name', 'AI')->value('code'));

        // Курс — только CEO
        $this->actingAsUser($this->hr)->put('/settings/references/exchange-rate', ['rate_to_uzs' => '12700'])->assertForbidden();
        $this->actingAsUser($this->ceo)->put('/settings/references/exchange-rate', ['rate_to_uzs' => '0'])->assertSessionHasErrors('rate_to_uzs');
        $this->actingAsUser($this->ceo)->put('/settings/references/exchange-rate', ['rate_to_uzs' => '12 700,5'])->assertSessionHasNoErrors();
        $this->actingAsUser($this->ceo)->put('/settings/references/exchange-rate', ['rate_to_uzs' => '12800'])->assertSessionHasNoErrors();
        $this->assertSame(1, ExchangeRate::whereDate('date', Format::today()->toDateString())->count());
        $this->assertSame(0, \Brick\Math\BigDecimal::of(\App\Services\References\ExchangeRates::rateFor('USD'))->compareTo('12800'));
    }

    public function test_teams(): void
    {
        $this->actingAsUser($this->manager)->get('/settings/teams')->assertForbidden();
        $newRop = $this->user('ROP');
        $this->actingAsUser($this->ceo)->post('/settings/teams', ['name' => 'Отдел 3', 'head_id' => $newRop->id])->assertSessionHasNoErrors();
        $team = Team::where('name', 'Отдел 3')->firstOrFail();
        $this->assertSame($team->id, $newRop->fresh()->team_id);
        $this->actingAsUser($this->ceo)->post('/settings/teams', ['name' => 'Отдел 4', 'head_id' => $this->manager->id])->assertSessionHasErrors('head_id');
        $this->actingAsUser($this->ceo)->post('/settings/teams', ['name' => 'Отдел 1'])->assertSessionHasErrors('name');
        // С сотрудниками удалить нельзя; пустой — мягко
        $this->actingAsUser($this->ceo)->delete("/settings/teams/{$this->team1->id}")->assertSessionHas('error');
        $this->otherManager->update(['team_id' => null]);
        $this->actingAsUser($this->ceo)->delete("/settings/teams/{$this->team2->id}")->assertSessionHasNoErrors();
        $this->assertSoftDeleted('teams', ['id' => $this->team2->id]);
        // Имя освобождается
        $this->actingAsUser($this->ceo)->post('/settings/teams', ['name' => 'Отдел 2'])->assertSessionHasNoErrors();
        $this->assertTrue(AuditLog::where('action', 'team.delete')->exists());
    }
}
