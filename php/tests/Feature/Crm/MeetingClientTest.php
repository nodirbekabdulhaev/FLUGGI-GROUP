<?php

namespace Tests\Feature\Crm;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Comment;
use App\Models\Contact;
use App\Models\Meeting;

/** Встречи (перенос, не состоялась, проведена), клиенты, контакты, реквизиты, комментарии. */
class MeetingClientTest extends CrmTestCase
{
    public function test_meeting_reschedule_no_show_and_done_rules(): void
    {
        $lead = $this->newLead($this->manager);
        $this->actingAsUser($this->manager)->post('/sales/meetings', ['lead_id' => $lead->id, 'starts_at' => '2030-05-01T10:00', 'type' => 'ZOOM', 'duration_min' => 30])->assertSessionHasNoErrors();
        $m = Meeting::firstOrFail();
        $this->assertSame('2030-05-01 05:00:00', $m->starts_at->format('Y-m-d H:i:s'));
        // Новая дата без статуса → «Перенесена»
        $this->actingAsUser($this->manager)->put("/sales/meetings/{$m->id}", ['starts_at' => '2030-05-02T11:00'])->assertSessionHasNoErrors();
        $this->assertSame('RESCHEDULED', $m->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'meeting.update')->exists());
        $this->actingAsUser($this->manager)->put("/sales/meetings/{$m->id}", ['status' => 'NO_SHOW'])->assertSessionHasNoErrors();
        $this->assertSame('NO_SHOW', $m->fresh()->status);
        // «Проведена» — только с результатом
        $this->actingAsUser($this->manager)->put("/sales/meetings/{$m->id}", ['status' => 'DONE'])->assertSessionHasErrors('status');
        $this->actingAsUser($this->manager)->post("/sales/meetings/{$m->id}/complete", ['result' => ''])->assertSessionHasErrors('result');
        $this->actingAsUser($this->manager)->post("/sales/meetings/{$m->id}/complete", ['result' => 'OK'])->assertSessionHasNoErrors();
        $this->actingAsUser($this->manager)->post("/sales/meetings/{$m->id}/complete", ['result' => 'OK'])->assertSessionHas('error');
        $this->actingAsUser($this->manager)->put("/sales/meetings/{$m->id}", ['status' => 'CANCELLED'])->assertSessionHas('error');
        $this->assertDatabaseHas('outbox_events', ['type' => 'meeting.completed']);
        // Отменённую встречу провести нельзя
        $this->actingAsUser($this->manager)->post('/sales/meetings', ['lead_id' => $lead->id, 'starts_at' => '2030-06-01T10:00', 'type' => 'PHONE'])->assertSessionHasNoErrors();
        $m2 = Meeting::where('type', 'PHONE')->firstOrFail();
        $this->actingAsUser($this->manager)->put("/sales/meetings/{$m2->id}", ['status' => 'CANCELLED'])->assertSessionHasNoErrors();
        $this->actingAsUser($this->manager)->post("/sales/meetings/{$m2->id}/complete", ['result' => 'x'])->assertSessionHas('error');
        // По закрытому лиду встречу не назначить; РОП видит встречи отдела, чужой менеджер — нет
        $this->actingAsUser($this->rop)->get('/sales/meetings?view=all')->assertOk()->assertSee($lead->title);
        $this->actingAsUser($this->otherManager)->put("/sales/meetings/{$m2->id}", ['status' => 'CONFIRMED'])->assertNotFound();
        $this->actingAsUser($this->manager)->post("/sales/leads/{$lead->id}/close", ['status' => 'PAUSED']);
        $this->actingAsUser($this->manager)->post('/sales/meetings', ['lead_id' => $lead->id, 'starts_at' => '2030-06-01T10:00', 'type' => 'PHONE'])->assertSessionHas('error');
    }

    public function test_client_contacts_and_requisites(): void
    {
        $this->actingAsUser($this->manager)->post('/clients', [
            'name' => 'Acme', 'type' => 'COMPANY', 'phone' => '+998901112233', 'contact' => ['full_name' => 'Ali', 'phone' => '+998901112234'],
        ])->assertSessionHasNoErrors();
        $client = Client::firstOrFail();
        $this->assertSame($this->manager->id, $client->owner_id);
        $this->assertTrue($client->contacts()->first()->is_primary);
        $this->assertTrue(AuditLog::where('action', 'client.create')->exists());

        $this->actingAsUser($this->manager)->post("/clients/{$client->id}/contacts", ['full_name' => 'Vali', 'is_primary' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('Vali', $client->contacts()->where('is_primary', true)->value('full_name'));
        $this->assertSame(1, $client->contacts()->where('is_primary', true)->count());
        $ali = Contact::where('full_name', 'Ali')->firstOrFail();
        $this->actingAsUser($this->manager)->put("/contacts/{$ali->id}", ['full_name' => 'Ali Valiyev', 'position' => 'CEO'])->assertSessionHasNoErrors();
        $this->assertSame('Ali Valiyev', $ali->fresh()->full_name);
        // Контакт в сделке удалить нельзя
        $this->actingAsUser($this->manager)->post('/sales/deals', ['client_id' => $client->id, 'contact_id' => $ali->id, 'title' => 'D', 'amount' => '10'])->assertSessionHasNoErrors();
        $this->actingAsUser($this->manager)->delete("/contacts/{$ali->id}")->assertSessionHas('error');
        $vali = Contact::where('full_name', 'Vali')->firstOrFail();
        $this->actingAsUser($this->manager)->delete("/contacts/{$vali->id}")->assertSessionHasNoErrors();
        $this->assertNull(Contact::find($vali->id));
        $this->actingAsUser($this->otherManager)->delete("/contacts/{$ali->id}")->assertNotFound();

        $this->actingAsUser($this->manager)->put("/clients/{$client->id}/requisites", ['inn' => '12345'])->assertSessionHasErrors('inn');
        $this->actingAsUser($this->manager)->put("/clients/{$client->id}/requisites", ['legalName' => 'OOO Acme', 'inn' => '123456789', 'mfo' => '00014', 'account' => '20208000900123456001'])->assertSessionHasNoErrors();
        $this->assertSame('123456789', $client->fresh()->requisites['inn']);
        $this->assertTrue(AuditLog::where('action', 'client.requisites')->exists());

        $this->actingAsUser($this->manager)->put("/clients/{$client->id}", ['name' => 'Acme 2', 'type' => 'COMPANY'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('activities', ['client_id' => $client->id, 'type' => 'client.updated']);
        $this->actingAsUser($this->manager)->get("/clients/{$client->id}")->assertOk()->assertSee('OOO Acme')->assertSee('Ali Valiyev');
        $this->actingAsUser($this->manager)->get('/clients?q=Ali')->assertOk()->assertSee('Acme 2');
    }

    public function test_comments_only_author_deletes(): void
    {
        $lead = $this->newLead($this->manager);
        $this->actingAsUser($this->manager)->post('/crm/comments', ['lead_id' => $lead->id, 'body' => 'Позвонить завтра'])->assertSessionHasNoErrors();
        $c = Comment::firstOrFail();
        $this->assertDatabaseHas('activities', ['lead_id' => $lead->id, 'type' => 'comment.added']);
        $this->actingAsUser($this->rop)->delete("/crm/comments/{$c->id}")->assertForbidden();
        $this->actingAsUser($this->manager)->delete("/crm/comments/{$c->id}")->assertSessionHasNoErrors();
        $this->assertSoftDeleted('comments', ['id' => $c->id]);
        $this->assertTrue(AuditLog::where('action', 'comment.delete')->exists());
        $this->actingAsUser($this->manager)->post('/crm/comments', ['body' => 'x'])->assertNotFound();
    }
}
