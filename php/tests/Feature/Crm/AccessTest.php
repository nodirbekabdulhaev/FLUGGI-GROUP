<?php

namespace Tests\Feature\Crm;

use App\Models\Lead;
use App\Models\User;

/** Разграничение данных CRM (ТЗ §65): OWN / TEAM / ALL, чужое — 404, без права — 403. */
class AccessTest extends CrmTestCase
{
    public function test_manager_sees_own_rop_sees_team_ceo_sees_all(): void
    {
        $own = $this->newLead($this->manager);
        $other = $this->newLead($this->otherManager, ['company_name' => 'Other Co', 'phone' => '+998935554433']);

        $this->actingAsUser($this->manager)->get('/sales/leads')->assertOk()->assertSee($own->title)->assertDontSee('Other Co');
        $this->actingAsUser($this->manager)->get("/sales/leads/{$other->id}")->assertNotFound();
        $this->actingAsUser($this->manager)->put("/sales/leads/{$other->id}", $this->leadData(['title' => 'hack']))->assertNotFound();
        $this->actingAsUser($this->manager)->post('/crm/comments', ['lead_id' => $other->id, 'body' => 'x'])->assertNotFound();
        $this->assertNotSame('hack', $other->fresh()->title);

        $this->actingAsUser($this->rop)->get('/sales/leads')->assertOk()->assertSee($own->title)->assertDontSee('Other Co');
        $this->actingAsUser($this->rop)->get("/sales/leads/{$other->id}")->assertNotFound();
        $this->actingAsUser($this->ceo)->get('/sales/leads')->assertOk()->assertSee($own->title)->assertSee('Other Co');
        $this->actingAsUser($this->ceo)->get("/sales/leads/{$other->id}")->assertOk();

        // Воронка тоже ограничена
        $ids = collect($this->actingAsUser($this->manager)->getJson('/sales/pipeline/data')->json('columns'))->pluck('items')->flatten(1)->pluck('id')->all();
        $this->assertSame([$own->id], $ids);
    }

    public function test_owner_assignment_scope(): void
    {
        $data = $this->leadData(['phone' => '+998900000000']);
        // Менеджер не может создать лид на другого менеджера
        $this->actingAsUser($this->manager)->post('/sales/leads', $data + ['owner_id' => $this->otherManager->id])->assertNotFound();
        // РОП — может в своём отделе, но не в чужом
        $this->actingAsUser($this->rop)->post('/sales/leads', $data + ['owner_id' => $this->manager->id])->assertSessionHasNoErrors();
        $this->assertSame($this->manager->id, Lead::latest('number')->value('owner_id'));
        $this->actingAsUser($this->rop)->post('/sales/leads', $data + ['owner_id' => $this->otherManager->id])->assertNotFound();
    }

    public function test_ceo_lead_goes_to_least_loaded_manager_and_ceo_cannot_be_owner(): void
    {
        $this->actingAsUser($this->ceo)->post('/sales/leads', $this->leadData())->assertSessionHasNoErrors();
        $lead = Lead::firstOrFail();
        $this->assertContains($lead->owner_id, [$this->manager->id, $this->otherManager->id]);
        foreach ([$this->ceo, $this->hr] as $u) {
            $this->actingAsUser($this->ceo)->post('/sales/leads', $this->leadData(['owner_id' => $u->id]))->assertSessionHasErrors('owner_id');
        }
        $this->actingAsUser($this->ceo)->post("/sales/leads/{$lead->id}/assign", ['owner_id' => $this->ceo->id])->assertSessionHasErrors('owner_id');
        $this->actingAsUser($this->ceo)->post("/sales/leads/{$lead->id}/assign", ['owner_id' => $this->rop->id])->assertSessionHasNoErrors();
        $lead->refresh();
        $this->assertSame($this->rop->id, $lead->owner_id);
        $this->assertSame($this->team1->id, $lead->team_id);
        $this->assertDatabaseHas('outbox_events', ['type' => 'lead.assigned']);
        // Менеджер без права распределения
        $this->actingAsUser($this->manager)->post("/sales/leads/{$lead->id}/assign", ['owner_id' => $this->manager->id])->assertForbidden();
    }

    public function test_executor_has_no_crm(): void
    {
        foreach (['/sales', '/sales/leads', '/sales/deals', '/clients', '/sales/pipeline', '/sales/pipeline/data', '/sales/meetings', '/settings/references', '/settings/teams'] as $url) {
            $this->actingAsUser($this->executor)->get($url)->assertForbidden();
        }
    }

    public function test_foreign_deal_client_meeting_are_404(): void
    {
        $lead = $this->newLead($this->otherManager);
        [$clientId, $dealId] = $this->convert($lead, $this->ceo);
        $this->actingAsUser($this->manager)->get("/sales/deals/{$dealId}")->assertNotFound();
        $this->actingAsUser($this->manager)->post("/sales/deals/{$dealId}/stage", ['stage' => 'NEGOTIATION'])->assertNotFound();
        $this->actingAsUser($this->manager)->get("/clients/{$clientId}")->assertNotFound();
        $this->actingAsUser($this->manager)->put("/clients/{$clientId}/requisites", ['inn' => '123456789'])->assertNotFound();
        $this->actingAsUser($this->manager)->post('/sales/meetings', ['deal_id' => $dealId, 'starts_at' => '2030-01-01T10:00', 'type' => 'ONLINE'])->assertNotFound();
        $this->actingAsUser($this->rop)->get("/sales/deals/{$dealId}")->assertNotFound();
        $this->actingAsUser($this->ceo)->get("/sales/deals/{$dealId}")->assertOk();
    }

    public function test_sales_index_redirects(): void
    {
        $this->actingAsUser($this->manager)->get('/sales')->assertRedirect('/sales/leads');
    }

    public function test_pages_render_in_both_languages(): void
    {
        $lead = $this->newLead($this->manager);
        [$clientId, $dealId] = $this->convert($lead, $this->rop);
        $lead2 = $this->newLead($this->manager, ['company_name' => 'Second', 'phone' => '+998931112233']);
        $pages = ['/sales/leads', "/sales/leads/{$lead->id}", "/sales/leads/{$lead2->id}", '/sales/deals', "/sales/deals/{$dealId}",
            '/sales/pipeline', '/sales/meetings', '/sales/meetings?view=done', '/sales/meetings?view=all', '/clients', "/clients/{$clientId}"];
        foreach (['ru' => 'Лиды', 'uz' => 'Lidlar'] as $locale => $word) {
            foreach ([$this->manager, $this->rop, $this->ceo] as $u) {
                $u->update(['locale' => $locale]);
                foreach ($pages as $url) {
                    $this->actingAsUser($u)->get($url)->assertOk();
                }
            }
            $this->actingAsUser($this->ceo)->get('/sales/leads')->assertSee($word);
            foreach (['/settings/references', '/settings/teams'] as $url) {
                $this->actingAsUser($this->ceo)->get($url)->assertOk();
                $this->hr->update(['locale' => $locale]);
                $this->actingAsUser($this->hr)->get($url)->assertOk();
            }
        }
    }
}
