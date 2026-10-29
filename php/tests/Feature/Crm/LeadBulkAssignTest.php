<?php

namespace Tests\Feature\Crm;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Meeting;
use App\Models\Notification;
use App\Models\OutboxEvent;
use App\Models\StageHistory;
use App\Models\Todo;
use App\Models\User;
use App\Services\Crm\LeadBulkAssignService;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Массовая передача лидов: область, пропуски, связанные записи, история, аудит, одно уведомление. */
class LeadBulkAssignTest extends CrmTestCase
{
    private User $manager2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager2 = $this->user('MANAGER', $this->team1, ['full_name' => 'Manager Three']);
    }

    private function lead(User $owner, array $attrs = []): Lead
    {
        return Lead::create($attrs + [
            'title' => 'Лид '.Str::random(6),
            'contact_name' => 'Контакт',
            'source_id' => LeadSource::where('code', 'OTHER')->value('id'),
            'owner_id' => $owner->id,
            'team_id' => $owner->team_id,
            'stage_id' => DealStage::where('code', 'NEW')->value('id'),
            'created_by_id' => $owner->id,
        ]);
    }

    private function bulk(User $as, array $data)
    {
        return $this->actingAsUser($as)->from('/sales/leads')->post('/sales/leads/bulk-assign', $data);
    }

    public function test_rop_transfers_team_leads_and_foreign_leads_are_skipped(): void
    {
        $a = $this->lead($this->manager);
        $b = $this->lead($this->manager);
        $foreign = $this->lead($this->otherManager);

        $this->bulk($this->rop, ['mode' => 'ids', 'ids' => [$a->id, $b->id, $foreign->id], 'owner_id' => $this->manager2->id, 'comment' => 'Отпуск'])
            ->assertRedirect('/sales/leads')->assertSessionHasNoErrors()
            ->assertSessionHas('ok', t('leads.bulk.done', ['moved' => 2, 'skipped' => 1, 'noAccess' => 1, 'same' => 0]));

        $this->assertSame($this->manager2->id, $a->fresh()->owner_id);
        $this->assertSame($this->team1->id, $a->fresh()->team_id);
        $this->assertSame($this->manager2->id, $b->fresh()->owner_id);
        $this->assertSame($this->otherManager->id, $foreign->fresh()->owner_id);

        // Таймлайн: от кого, кому, кем, комментарий, отметка массовой передачи
        $act = Activity::where('lead_id', $a->id)->where('type', 'lead.assigned')->firstOrFail();
        $this->assertSame($this->rop->id, $act->actor_id);
        $this->assertSame('Manager One', $act->payload['from']);
        $this->assertSame('Manager Three', $act->payload['to']);
        $this->assertSame('Отпуск', $act->payload['comment']);
        $this->assertTrue($act->payload['bulk']);
        $batch = $act->payload['batchId'];
        $this->assertFalse(Activity::where('lead_id', $foreign->id)->where('type', 'lead.assigned')->exists());

        // Аудит по каждому лиду + итоговая запись
        $rows = AuditLog::where('action', 'lead.assign')->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $rows->pluck('entity_id')->all());
        $this->assertEquals(['old' => $this->manager->id, 'new' => $this->manager2->id], $rows[0]->changes['owner_id']);
        $this->assertSame($batch, $rows[0]->changes['bulk_batch']['new']);
        $summary = AuditLog::where('action', 'lead.bulk_assign')->sole();
        $this->assertSame($this->rop->id, $summary->actor_id);
        $this->assertSame(2, $summary->changes['count']['new']);
        $this->assertSame($this->manager->id, $summary->changes['from']['new']);
        $this->assertSame($this->manager2->id, $summary->changes['to']['new']);
        $this->assertSame($batch, $summary->changes['batch']['new']);
        $this->assertEquals(['noAccess' => 1, 'same' => 0], $summary->changes['skipped']['new']);

        // События: по лиду (bulk) + одно итоговое
        $events = OutboxEvent::where('type', 'lead.assigned')->get();
        $this->assertCount(2, $events);
        $this->assertTrue($events[0]->payload['bulk']);
        $this->assertSame($this->manager->id, $events[0]->payload['previousOwnerId']);
        $sum = OutboxEvent::where('type', 'lead.bulk_assigned')->sole()->payload;
        $this->assertEquals(['batchId' => $batch, 'fromOwnerId' => $this->manager->id, 'toOwnerId' => $this->manager2->id, 'count' => 2, 'byUserId' => $this->rop->id],
            array_intersect_key($sum, array_flip(['batchId', 'fromOwnerId', 'toOwnerId', 'count', 'byUserId'])));

        // Новый ответственный вне области РОП — как при одиночной передаче (404)
        $this->bulk($this->rop, ['mode' => 'ids', 'ids' => [$a->id], 'owner_id' => $this->otherManager->id])->assertNotFound();
        $this->assertSame($this->manager2->id, $a->fresh()->owner_id);
    }

    public function test_manager_without_assign_right_is_forbidden_and_sees_no_checkboxes(): void
    {
        $a = $this->lead($this->manager);
        $this->bulk($this->manager, ['mode' => 'ids', 'ids' => [$a->id], 'owner_id' => $this->manager->id])->assertForbidden();
        $this->actingAsUser($this->manager)->getJson('/sales/leads/bulk-assign/preview?mode=ids&ids[]='.$a->id)->assertForbidden();
        $this->actingAsUser($this->manager)->get('/sales/leads')->assertOk()->assertDontSee(t('leads.bulk.selectPage'))->assertDontSee(t('leads.bulk.transferManager'));
        $this->actingAsUser($this->rop)->get('/sales/leads')->assertOk()->assertSee(t('leads.bulk.selectPage'))->assertSee(t('leads.bulk.transferManager'));
        $this->assertSame($this->manager->id, $a->fresh()->owner_id);
    }

    public function test_all_leads_of_manager_with_preview_and_status_filter(): void
    {
        $open = [$this->lead($this->manager), $this->lead($this->manager), $this->lead($this->manager)];
        $closed = $this->lead($this->manager, ['status' => 'LOST', 'closed_at' => now()]);
        $this->lead($this->otherManager);
        $url = '/sales/leads/bulk-assign/preview?'.http_build_query(['mode' => 'owner', 'from_owner_id' => $this->manager->id, 'owner_id' => $this->manager2->id]);

        $this->actingAsUser($this->ceo)->getJson($url.'&lead_status=open')->assertOk()
            ->assertExactJson(['total' => 3, 'transferable' => 3, 'noAccess' => 0, 'same' => 0]);
        $this->actingAsUser($this->ceo)->getJson($url.'&lead_status=all')->assertOk()->assertJson(['total' => 4, 'transferable' => 4]);
        // Фильтр списка — тот же расчёт
        $this->actingAsUser($this->ceo)->getJson('/sales/leads/bulk-assign/preview?'.http_build_query(['mode' => 'filter', 'filter' => ['owner_id' => $this->manager->id, 'status' => 'any']]))
            ->assertOk()->assertJson(['total' => 4, 'transferable' => 4]);
        // Менеджер не выбран — ошибка поля
        $this->actingAsUser($this->ceo)->getJson('/sales/leads/bulk-assign/preview?mode=owner')->assertStatus(422)->assertJsonValidationErrors('from_owner_id');

        $this->bulk($this->ceo, ['mode' => 'owner', 'from_owner_id' => $this->manager->id, 'lead_status' => 'open', 'owner_id' => $this->manager2->id])
            ->assertSessionHasNoErrors();
        foreach ($open as $l) {
            $this->assertSame($this->manager2->id, $l->fresh()->owner_id);
        }
        $this->assertSame($this->manager->id, $closed->fresh()->owner_id);
        $this->assertSame(3, AuditLog::where('action', 'lead.bulk_assign')->sole()->changes['count']['new']);

        // Блокированный менеджер остаётся в списке «от кого» — его лиды и передают
        $this->manager->update(['status' => 'BLOCKED']);
        $this->as($this->ceo);
        $this->assertArrayHasKey($this->manager->id, LeadBulkAssignService::fromOwners());
    }

    public function test_leads_already_owned_by_target_are_skipped(): void
    {
        $a = $this->lead($this->manager);
        $own = $this->lead($this->manager2);
        $this->actingAsUser($this->rop)->getJson('/sales/leads/bulk-assign/preview?'.http_build_query(['mode' => 'ids', 'ids' => [$a->id, $own->id], 'owner_id' => $this->manager2->id]))
            ->assertExactJson(['total' => 2, 'transferable' => 1, 'noAccess' => 0, 'same' => 1]);
        $this->bulk($this->rop, ['mode' => 'ids', 'ids' => [$a->id, $own->id], 'owner_id' => $this->manager2->id])
            ->assertSessionHas('ok', t('leads.bulk.done', ['moved' => 1, 'skipped' => 1, 'noAccess' => 0, 'same' => 1]));
        $this->assertSame(0, Activity::where('lead_id', $own->id)->where('type', 'lead.assigned')->count());
        $this->assertSame(1, AuditLog::where('action', 'lead.assign')->count());

        // Все уже у этого менеджера — ничего не пишется
        $this->bulk($this->rop, ['mode' => 'ids', 'ids' => [$own->id], 'owner_id' => $this->manager2->id])->assertSessionHasNoErrors();
        $this->assertSame(1, AuditLog::where('action', 'lead.bulk_assign')->count());
    }

    /** @return array{0: Lead, 1: Deal, 2: Client, 3: Meeting, 4: Meeting, 5: Meeting, 6: Todo, 7: Todo, 8: Todo} */
    private function convertedLeadWithWork(string $phone): array
    {
        $lead = $this->newLead($this->manager, ['phone' => $phone]);
        [$clientId, $dealId] = $this->convert($lead, $this->ceo);
        $meeting = fn (array $a) => Meeting::create($a + ['lead_id' => $lead->id, 'manager_id' => $this->manager->id, 'team_id' => $this->team1->id,
            'rop_id' => $this->rop->id, 'type' => 'ONLINE', 'status' => 'SCHEDULED', 'starts_at' => now()->addDays(2), 'created_by_id' => $this->manager->id]);
        $future = $meeting([]);
        DB::table('meeting_participants')->insert(['id' => (string) Str::uuid(), 'meeting_id' => $future->id, 'user_id' => $this->manager->id, 'created_at' => now()]);
        $past = $meeting(['starts_at' => now()->subDay()]);
        $foreign = $meeting(['manager_id' => $this->rop->id]);
        $todo = fn (array $a) => Todo::create($a + ['title' => 'Позвонить', 'owner_id' => $this->manager->id, 'creator_id' => $this->manager->id, 'status' => 'OPEN', 'lead_id' => $lead->id]);
        $openTodo = $todo([]);
        $doneTodo = $todo(['status' => 'DONE', 'completed_at' => now()]);
        $otherTodo = $todo(['owner_id' => $this->rop->id, 'deal_id' => $dealId, 'lead_id' => null]);

        return [$lead->fresh(), Deal::findOrFail($dealId), Client::findOrFail($clientId), $future, $past, $foreign, $openTodo, $doneTodo, $otherTodo];
    }

    public function test_related_records_move_only_when_chosen_and_only_from_previous_owner(): void
    {
        // Без опций — передаётся только лид
        [$lead, $deal, $client, $future, , , $openTodo] = $this->convertedLeadWithWork('+998901110000');
        $this->bulk($this->rop, ['mode' => 'ids', 'ids' => [$lead->id], 'owner_id' => $this->manager2->id])->assertSessionHasNoErrors();
        $this->assertSame($this->manager2->id, $lead->fresh()->owner_id);
        $this->assertSame($this->manager->id, $deal->fresh()->owner_id);
        $this->assertSame($this->manager->id, $client->fresh()->owner_id);
        $this->assertSame($this->manager->id, $future->fresh()->manager_id);
        $this->assertSame($this->manager->id, $openTodo->fresh()->owner_id);

        // С опциями — открытая сделка, клиент, будущая встреча и открытое дело прежнего ответственного
        [$lead, $deal, $client, $future, $past, $foreign, $openTodo, $doneTodo, $otherTodo] = $this->convertedLeadWithWork('+998902220000');
        $this->bulk($this->rop, ['mode' => 'ids', 'ids' => [$lead->id], 'owner_id' => $this->manager2->id,
            'with_deals' => '1', 'with_clients' => '1', 'with_tasks' => '1'])->assertSessionHasNoErrors()
            ->assertSessionHas('bulkResult', fn ($r) => $r['moved'] === 1 && $r['deals'] === 1 && $r['clients'] === 1 && $r['meetings'] === 1 && $r['todos'] === 1);
        $this->assertSame($this->manager2->id, $deal->fresh()->owner_id);
        $this->assertSame($this->manager2->id, $client->fresh()->owner_id);
        $this->assertSame($this->manager2->id, $future->fresh()->manager_id);
        $this->assertSame([$this->manager2->id], $future->fresh()->participantIds());
        $this->assertSame($this->manager->id, $past->fresh()->manager_id);
        $this->assertSame($this->rop->id, $foreign->fresh()->manager_id);
        $this->assertSame($this->manager2->id, $openTodo->fresh()->owner_id);
        $this->assertSame($this->manager->id, $doneTodo->fresh()->owner_id);
        $this->assertSame($this->rop->id, $otherTodo->fresh()->owner_id);
        $this->assertTrue(Activity::where('deal_id', $deal->id)->where('type', 'deal.assigned')->where('payload->bulk', true)->exists());
        $this->assertTrue(Activity::where('client_id', $client->id)->where('type', 'client.assigned')->exists());
        foreach (['deal.assign', 'client.assign', 'meeting.update', 'todo.update'] as $action) {
            $this->assertTrue(AuditLog::where('action', $action)->where('changes->bulk_batch->new', '<>', '')->exists(), $action);
        }

        // Закрытая сделка не передаётся
        [$lead3, $deal3] = $this->convertedLeadWithWork('+998903330000');
        $deal3->update(['status' => 'LOST', 'closed_at' => now()]);
        $this->bulk($this->rop, ['mode' => 'ids', 'ids' => [$lead3->id], 'owner_id' => $this->manager2->id, 'with_deals' => '1'])->assertSessionHasNoErrors();
        $this->assertSame($this->manager->id, $deal3->fresh()->owner_id);
    }

    public function test_history_is_preserved_and_shown_on_lead_card(): void
    {
        $lead = $this->newLead($this->manager);
        $this->actingAsUser($this->manager)->post("/sales/leads/{$lead->id}/stage", ['stage' => 'CONTACTED'])->assertSessionHasNoErrors();
        $this->actingAsUser($this->manager)->post('/crm/comments', ['lead_id' => $lead->id, 'body' => 'Клиент просил перезвонить'])->assertSessionHasNoErrors();
        $before = Activity::where('lead_id', $lead->id)->orderBy('id')->get(['id', 'type', 'actor_id', 'payload', 'created_at'])->toArray();
        $stages = StageHistory::where('lead_id', $lead->id)->orderBy('id')->get()->toArray();
        $this->assertNotEmpty($before);

        $this->bulk($this->rop, ['mode' => 'ids', 'ids' => [$lead->id], 'owner_id' => $this->manager2->id, 'comment' => 'Перераспределение'])->assertSessionHasNoErrors();

        $after = Activity::where('lead_id', $lead->id)->orderBy('id')->get(['id', 'type', 'actor_id', 'payload', 'created_at']);
        $this->assertCount(count($before) + 1, $after);
        $this->assertSame($before, $after->whereIn('id', array_column($before, 'id'))->values()->toArray());
        $this->assertSame($stages, StageHistory::where('lead_id', $lead->id)->orderBy('id')->get()->toArray());
        $this->assertTrue(DB::table('comments')->where('lead_id', $lead->id)->whereNull('deleted_at')->exists());

        $this->actingAsUser($this->manager2)->get("/sales/leads/{$lead->id}")->assertOk()
            ->assertSee(t('crm.common.assignedFromTo', ['from' => 'Manager One', 'to' => 'Manager Three']))
            ->assertSee(t('crm.common.bulkTransfer'))->assertSee('Перераспределение')->assertSee('Rop Head')
            ->assertSee('Клиент просил перезвонить');
    }

    public function test_one_summary_notification_instead_of_per_lead(): void
    {
        $leads = [$this->lead($this->manager), $this->lead($this->manager), $this->lead($this->manager)];
        $this->bulk($this->rop, ['mode' => 'ids', 'ids' => array_map(fn ($l) => $l->id, $leads), 'owner_id' => $this->manager2->id])->assertSessionHasNoErrors();
        while (Outbox::processBatch() > 0);

        $this->assertSame(0, Notification::where('type', 'lead.assigned')->count());
        $note = Notification::where('user_id', $this->manager2->id)->where('type', 'lead.bulk_assigned')->sole();
        $this->assertSame(t('notifications.events.leadBulkAssigned', ['count' => 3, 'from' => 'Manager One']), $note->title);
        $batch = AuditLog::where('action', 'lead.bulk_assign')->sole()->changes['batch']['new'];
        $this->assertSame(LeadBulkAssignService::link($this->manager2->id, $batch), $note->link);
        $this->assertSame(1, Notification::where('user_id', $this->manager->id)->where('type', 'lead.bulk_assigned')->count());
        $this->assertSame(0, Notification::where('user_id', $this->rop->id)->count());

        // Ссылка из уведомления — список переданных лидов
        $this->lead($this->manager2, ['title' => 'Свой давний лид']);
        $page = $this->actingAsUser($this->manager2)->get($note->link)->assertOk()->assertSee(t('leads.bulk.batchFilter'))->assertDontSee('Свой давний лид');
        foreach ($leads as $l) {
            $page->assertSee($l->title);
        }

        // Одиночная передача по-прежнему уведомляет
        $this->actingAsUser($this->rop)->post("/sales/leads/{$leads[0]->id}/assign", ['owner_id' => $this->manager->id])->assertSessionHasNoErrors();
        while (Outbox::processBatch() > 0);
        $this->assertSame(1, Notification::where('user_id', $this->manager->id)->where('type', 'lead.assigned')->count());
    }

    public function test_selection_limit_and_validation(): void
    {
        $ids = array_map(fn () => (string) Str::uuid(), range(1, LeadBulkAssignService::MAX + 1));
        $this->bulk($this->ceo, ['mode' => 'ids', 'ids' => $ids, 'owner_id' => $this->manager2->id])->assertSessionHasErrors('ids');
        $this->bulk($this->ceo, ['mode' => 'ids', 'ids' => [], 'owner_id' => $this->manager2->id])->assertSessionHasErrors('ids');
        $this->bulk($this->ceo, ['mode' => 'ids', 'ids' => ['not-a-uuid'], 'owner_id' => $this->manager2->id])->assertSessionHasErrors('ids.0');
        $this->bulk($this->ceo, ['mode' => 'ids', 'ids' => [$this->lead($this->manager)->id]])->assertSessionHasErrors('owner_id');
        $this->bulk($this->ceo, ['mode' => 'nope', 'owner_id' => $this->manager2->id])->assertSessionHasErrors('mode');
        // Нового ответственного с другой ролью назначить нельзя
        $this->bulk($this->ceo, ['mode' => 'ids', 'ids' => [$this->lead($this->manager)->id], 'owner_id' => $this->hr->id])->assertSessionHasErrors('owner_id');
        $this->assertSame(0, AuditLog::where('action', 'lead.bulk_assign')->count());
    }
}
