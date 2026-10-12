<?php

namespace Tests\Feature\Crm;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\OutboxEvent;
use App\Models\StageHistory;
use Illuminate\Support\Facades\DB;

/** Сценарий §84, шаги 3–8: лид → встреча → квалификация в клиента и сделку. */
class LeadFlowTest extends CrmTestCase
{
    public function test_manager_creates_lead_meeting_and_rop_converts_it(): void
    {
        $lead = $this->newLead($this->manager);
        $this->assertSame('L-00001', \App\Support\Format::code('L', $lead->number));
        $this->assertSame('NEW', $lead->stage->code);
        $this->assertSame($this->manager->id, $lead->owner_id);
        $this->assertSame($this->team1->id, $lead->team_id);
        $this->assertSame('Hamza Textile — SMM', $lead->title);
        $this->assertContains($lead->score_level, ['LOW', 'MEDIUM', 'HIGH', 'HOT']);
        $this->assertGreaterThan(0, $lead->score);
        $this->assertTrue(OutboxEvent::where('type', 'lead.created')->exists());
        $this->assertTrue(AuditLog::where('action', 'lead.create')->where('entity_id', $lead->id)->exists());

        // Лид на доске воронки
        $board = $this->actingAsUser($this->manager)->getJson('/sales/pipeline/data')->assertOk()->json();
        $new = collect($board['columns'])->firstWhere('code', 'NEW');
        $this->assertContains($lead->id, array_column($new['items'], 'id'));

        // Нельзя «перепрыгнуть» на «Назначена встреча» без встречи
        $this->actingAsUser($this->manager)->postJson("/sales/leads/{$lead->id}/stage", ['stage' => 'MEETING_SCHEDULED'])->assertStatus(422);

        // Встреча → лид на этапе «Назначена встреча», РОП отдела — участник
        $this->actingAsUser($this->manager)->post('/sales/meetings', [
            'lead_id' => $lead->id, 'starts_at' => now('Asia/Tashkent')->addDay()->format('Y-m-d\TH:i'), 'type' => 'OFFLINE',
        ])->assertSessionHasNoErrors();
        $meeting = Meeting::firstOrFail();
        $this->assertSame($this->rop->id, $meeting->rop_id);
        $this->assertSame('MEETING_SCHEDULED', $lead->fresh()->stage->code);
        $this->assertTrue(OutboxEvent::where('type', 'meeting.created')->exists());

        // Встреча проведена → лид на этапе «Встреча проведена»
        $this->actingAsUser($this->manager)->post("/sales/meetings/{$meeting->id}/complete", ['result' => 'Нужен SMM на 3 месяца'])->assertSessionHasNoErrors();
        $this->assertSame('MEETING_DONE', $lead->fresh()->stage->code);
        $this->assertNotNull($lead->fresh()->last_contact_at);

        // РОП квалифицирует → клиент + сделка
        [$clientId, $dealId] = $this->convert($lead, $this->rop);
        $deal = Deal::with('stage')->findOrFail($dealId);
        $this->assertSame('NEED_DEFINED', $deal->stage->code);
        $this->assertSame($this->manager->id, $deal->owner_id);
        $this->assertSame('9000000.00', (string) $deal->amount_uzs);
        $client = Client::with('contacts')->findOrFail($clientId);
        $this->assertSame('Hamza', $client->contacts->first()->full_name);
        $this->assertTrue($client->contacts->first()->is_primary);
        $this->assertSame('CONVERTED', $lead->fresh()->status);
        $this->assertTrue(OutboxEvent::where('type', 'lead.converted')->exists());
        $this->assertTrue(OutboxEvent::where('type', 'deal.created')->exists());

        // Карточка сделки видна менеджеру; таймлайн включает историю лида
        $this->actingAsUser($this->manager)->get("/sales/deals/{$dealId}")->assertOk()
            ->assertSee('Hamza Textile')->assertSee(t('crm.activity.lead.converted'))->assertSee(t('crm.activity.meeting.completed'));
        $types = \App\Services\Crm\TimelineService::activities(['deal_id' => $dealId])->pluck('type')->all();
        foreach (['lead.created', 'meeting.created', 'meeting.completed', 'lead.converted'] as $type) {
            $this->assertContains($type, $types);
        }
        $history = StageHistory::with('toStage')->where('lead_id', $lead->id)->orderBy('created_at')->get()->pluck('toStage.code')->all();
        $this->assertSame(['NEW', 'MEETING_SCHEDULED', 'MEETING_DONE'], $history);
        $this->actingAsUser($this->manager)->get("/clients/{$clientId}")->assertOk()->assertSee($deal->title);
    }

    public function test_minimum_fields_are_required(): void
    {
        $this->actingAsUser($this->manager)->post('/sales/leads', $this->leadData(['contact_name' => null, 'company_name' => null, 'phone' => null]))
            ->assertSessionHasErrors(['contact_name', 'phone']);
        $this->actingAsUser($this->manager)->post('/sales/leads', $this->leadData(['service_id' => null, 'source_id' => null]))
            ->assertSessionHasErrors(['service_id', 'source_id']);
        // Telegram вместо телефона — достаточно
        $this->actingAsUser($this->manager)->post('/sales/leads', $this->leadData(['phone' => null, 'telegram' => '@hamza']))->assertSessionHasNoErrors();
        $this->assertSame(1, Lead::count());
    }

    public function test_close_requires_reason_and_comment_for_other(): void
    {
        $lead = $this->newLead($this->manager);
        $this->actingAsUser($this->manager)->post("/sales/leads/{$lead->id}/close", ['status' => 'LOST'])->assertSessionHasErrors('loss_reason_id');
        $this->actingAsUser($this->manager)->post("/sales/leads/{$lead->id}/close", ['status' => 'LOST', 'loss_reason_id' => $this->lossReason('OTHER')])
            ->assertSessionHasErrors('comment');
        $this->assertSame('OPEN', $lead->fresh()->status);
        $this->actingAsUser($this->manager)->post("/sales/leads/{$lead->id}/close", ['status' => 'LOST', 'loss_reason_id' => $this->lossReason('TOO_EXPENSIVE')])
            ->assertSessionHasNoErrors();
        $lead->refresh();
        $this->assertSame('LOST', $lead->status);
        $this->assertSame('Слишком дорого', $lead->lossReason->name_ru);
        $this->assertTrue(OutboxEvent::where('type', 'lead.closed')->exists());
        // Повторно закрыть нельзя; вернуть в работу — можно
        $this->actingAsUser($this->manager)->post("/sales/leads/{$lead->id}/close", ['status' => 'PAUSED'])->assertSessionHas('error');
        $this->actingAsUser($this->manager)->post("/sales/leads/{$lead->id}/reopen")->assertSessionHasNoErrors();
        $this->assertSame('OPEN', $lead->fresh()->status);
        $this->assertNull($lead->fresh()->loss_reason_id);
        // Пауза без причины допустима
        $this->actingAsUser($this->manager)->post("/sales/leads/{$lead->id}/close", ['status' => 'PAUSED'])->assertSessionHasNoErrors();
        $this->assertSame('PAUSED', $lead->fresh()->status);
        // Закрытый лид не двигается по этапам
        $this->actingAsUser($this->manager)->postJson("/sales/leads/{$lead->id}/stage", ['stage' => 'CONTACTED'])->assertStatus(422);
    }

    public function test_usd_budget_and_deal_amount_change_tracked(): void
    {
        $lead = $this->newLead($this->manager, ['budget' => '1000', 'currency' => 'USD']);
        $this->assertSame('12650000.00', (string) $lead->budget_uzs);
        [, $dealId] = $this->convert($lead, $this->rop, ['client_name' => 'USD Co', 'amount' => '1000', 'currency' => 'USD']);
        $deal = Deal::findOrFail($dealId);
        $this->actingAsUser($this->manager)->put("/sales/deals/{$dealId}", [
            'title' => $deal->title, 'amount' => '1 200', 'currency' => 'USD',
        ])->assertSessionHasNoErrors();
        $this->assertSame('15180000.00', (string) $deal->fresh()->amount_uzs);
        $this->assertSame('deal.amount_changed', Activity::where('deal_id', $dealId)->latest('created_at')->value('type'));
        $audit = AuditLog::where('action', 'deal.update')->firstOrFail();
        $this->assertSame(['old' => '1000.00', 'new' => '1200.00'], $audit->changes['amount']);
    }

    public function test_contacted_stage_sets_last_contact_and_rescoring(): void
    {
        $lead = $this->newLead($this->manager);
        $before = $lead->score;
        $this->actingAsUser($this->manager)->post("/sales/leads/{$lead->id}/stage", ['stage' => 'QUALIFICATION'])->assertSessionHasNoErrors();
        $this->assertGreaterThan($before, $lead->fresh()->score);
        $this->actingAsUser($this->manager)->post("/sales/leads/{$lead->id}/stage", ['stage' => 'CONTACTED'])->assertSessionHasNoErrors();
        $this->assertNotNull($lead->fresh()->last_contact_at);
        $this->assertTrue(AuditLog::where('action', 'lead.stage_change')->exists());
        // Интерес и размер компании влияют на оценку
        $this->actingAsUser($this->manager)->put("/sales/leads/{$lead->id}", $this->leadData(['interest' => 5, 'company_size' => 'LARGE', 'priority' => 'URGENT']))->assertSessionHasNoErrors();
        $this->assertGreaterThan($before, $lead->fresh()->score);
        $this->assertTrue(Activity::where('lead_id', $lead->id)->where('type', 'lead.updated')->exists());
    }

    public function test_converted_lead_cannot_be_reopened_or_deleted(): void
    {
        $lead = $this->newLead($this->manager);
        $this->convert($lead, $this->rop);
        $this->actingAsUser($this->rop)->post("/sales/leads/{$lead->id}/reopen")->assertSessionHas('error');
        $this->actingAsUser($this->rop)->delete("/sales/leads/{$lead->id}")->assertSessionHas('error');
        $this->actingAsUser($this->rop)->post("/sales/leads/{$lead->id}/convert", ['client_name' => 'X', 'amount' => '1'])->assertSessionHas('error');
        $this->assertNull($lead->fresh()->deleted_at);
    }

    public function test_convert_to_existing_client_marks_repeat(): void
    {
        $first = $this->newLead($this->manager);
        [$clientId] = $this->convert($first, $this->rop);
        $second = $this->newLead($this->manager, ['company_name' => 'Hamza Again', 'phone' => '+998907777777']);
        $this->actingAsUser($this->rop)->post("/sales/leads/{$second->id}/convert", ['mode' => 'existing', 'client_id' => $clientId, 'amount' => '500'])->assertSessionHasNoErrors();
        $deal = Deal::findOrFail($second->fresh()->deal_id);
        $this->assertSame($clientId, $deal->client_id);
        $this->assertTrue($deal->is_repeat);
        $this->assertSame(1, Client::count());
    }

    public function test_delete_is_soft_and_audited(): void
    {
        $lead = $this->newLead($this->rop);
        $this->actingAsUser($this->rop)->delete("/sales/leads/{$lead->id}")->assertRedirect('/sales/leads');
        $this->assertSoftDeleted('leads', ['id' => $lead->id]);
        $this->assertTrue(AuditLog::where('action', 'lead.delete')->exists());
        // У менеджера нет права удалять лиды
        $lead2 = $this->newLead($this->manager);
        $this->actingAsUser($this->manager)->delete("/sales/leads/{$lead2->id}")->assertForbidden();
    }

    public function test_stage_history_and_activities_are_immutable(): void
    {
        $this->newLead($this->manager);
        $h = StageHistory::firstOrFail();
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('stage_history')->where('id', $h->id)->delete();
    }

    public function test_activities_cannot_be_updated(): void
    {
        $this->newLead($this->manager);
        $a = Activity::firstOrFail();
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('activities')->where('id', $a->id)->update(['type' => 'x']);
    }

    public function test_duplicate_warning_by_phone_instagram_email(): void
    {
        $lead = $this->newLead($this->manager, ['instagram' => '@hamza.tex', 'email' => 'info@hamza.uz']);
        $res = $this->actingAsUser($this->manager)->getJson('/sales/leads/duplicates?phone='.urlencode('90 123-45-67'))->assertOk()->json();
        $this->assertSame([$lead->id], array_column($res['leads'], 'id'));
        $res = $this->actingAsUser($this->manager)->getJson('/sales/leads/duplicates?instagram='.urlencode('https://instagram.com/Hamza.Tex/'))->json();
        $this->assertSame([$lead->id], array_column($res['leads'], 'id'));
        $res = $this->actingAsUser($this->manager)->getJson('/sales/leads/duplicates?email=INFO@hamza.uz')->json();
        $this->assertSame([$lead->id], array_column($res['leads'], 'id'));
        // Чужие (вне области) записи не раскрываются
        $res = $this->actingAsUser($this->otherManager)->getJson('/sales/leads/duplicates?phone=901234567')->json();
        $this->assertSame([], $res['leads']);
        // Клиент с тем же телефоном контакта
        $this->convert($lead, $this->rop);
        $res = $this->actingAsUser($this->manager)->getJson('/sales/leads/duplicates?phone=998901234567')->json();
        $this->assertCount(1, $res['clients']);
        $this->actingAsUser($this->manager)->get("/sales/leads/{$lead->id}")->assertOk();
    }
}
