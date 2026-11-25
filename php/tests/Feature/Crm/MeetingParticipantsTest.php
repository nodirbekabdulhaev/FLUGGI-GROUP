<?php

namespace Tests\Feature\Crm;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\Notification;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\Automation\ReminderService;
use App\Support\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Встречи с несколькими участниками: отметка «Участвует во встречах» и личные слоты,
 * сетка свободно/занято, запрет пересечений, ответственный за результат, уведомления участникам.
 */
class MeetingParticipantsTest extends CrmTestCase
{
    private User $second;

    private Lead $lead;

    /** Понедельник и суббота (по Ташкенту). */
    private const MONDAY = '2030-05-06';

    private const SATURDAY = '2030-05-11';

    protected function setUp(): void
    {
        parent::setUp();
        $this->second = $this->user('MANAGER', $this->team1, ['full_name' => 'Meet Second']);
        $this->flag($this->manager, ['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '09:00', 'to' => '12:00', 'slot' => 60]);
        $this->flag($this->second, ['days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'to' => '10:30', 'slot' => 30]);
        $this->lead = $this->newLead($this->manager);
    }

    private function flag(User $u, array $slots): void
    {
        $u->forceFill(['meeting_participant' => true, 'meeting_slots' => json_encode($slots)])->save();
    }

    private function book(User $as, array $participants, string $at, int $minutes = 60, array $extra = []): Meeting
    {
        $this->actingAsUser($as)->post('/sales/meetings', $extra + [
            'lead_id' => $this->lead->id, 'starts_at' => $at, 'duration_min' => $minutes, 'type' => 'OFFLINE',
            'participant_ids' => array_map(fn (User $u) => $u->id, $participants),
        ])->assertSessionHasNoErrors();

        return Meeting::latest('created_at')->latest('id')->firstOrFail();
    }

    private function slots(User $as, string $date, array $users, ?string $exclude = null): array
    {
        $q = http_build_query(array_filter(['date' => $date, 'users' => array_map(fn (User $u) => $u->id, $users), 'exclude' => $exclude]));

        return collect($this->actingAsUser($as)->getJson('/sales/meetings/slots?'.$q)->assertOk()->json('users'))->keyBy('id')->all();
    }

    private static function slot(array $row, string $time): array
    {
        return collect($row['slots'])->firstWhere('time', $time);
    }

    public function test_flag_and_slot_settings_are_saved_and_audited(): void
    {
        $u = $this->user('MANAGER', $this->team1, ['full_name' => 'Flag Me']);
        $form = ['full_name' => 'Flag Me', 'email' => $u->email, 'role_code' => 'MANAGER', 'team_id' => $this->team1->id];

        $this->actingAsUser($this->hr)->patch("/team/employees/{$u->id}", $form + [
            'meeting_participant' => '1', 'meeting_days' => ['1', '3', '5'], 'meeting_from' => '10:00', 'meeting_to' => '14:00', 'meeting_slot' => '30',
        ])->assertSessionHasNoErrors();
        $u->refresh();
        $this->assertSame(1, (int) $u->meeting_participant);
        $this->assertEquals(['days' => [1, 3, 5], 'from' => '10:00', 'to' => '14:00', 'slot' => 30], json_decode($u->meeting_slots, true));
        $audit = AuditLog::where('action', 'user.update')->where('entity_id', $u->id)->latest('created_at')->firstOrFail();
        $changes = is_array($audit->changes) ? $audit->changes : json_decode($audit->changes, true);
        $this->assertTrue($changes['meetingParticipant']['new']);
        $this->assertSame('1,3,5 10:00–14:00 / 30', $changes['meetingSlots']['new']);

        // Интервал короче слота и пустые дни — ошибка; длина слота — только из списка
        $this->actingAsUser($this->hr)->patch("/team/employees/{$u->id}", $form + ['meeting_participant' => '1', 'meeting_days' => ['1'], 'meeting_from' => '10:00', 'meeting_to' => '10:15', 'meeting_slot' => '30'])
            ->assertSessionHasErrors('meeting_to');
        $this->actingAsUser($this->hr)->patch("/team/employees/{$u->id}", $form + ['meeting_participant' => '1', 'meeting_from' => '10:00', 'meeting_to' => '12:00', 'meeting_slot' => '30'])
            ->assertSessionHasErrors('meeting_days');
        $this->actingAsUser($this->hr)->patch("/team/employees/{$u->id}", $form + ['meeting_participant' => '1', 'meeting_days' => ['1'], 'meeting_slot' => '25'])
            ->assertSessionHasErrors('meeting_slot');
        // Снять отметку; форма без полей встреч ничего не меняет
        $this->actingAsUser($this->hr)->patch("/team/employees/{$u->id}", $form + ['meeting_participant' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(0, (int) $u->fresh()->meeting_participant);
        $this->assertSame(30, json_decode($u->fresh()->meeting_slots, true)['slot']);
        // Менеджер сотрудниками не управляет
        $this->actingAsUser($this->manager)->patch("/team/employees/{$u->id}", $form + ['meeting_participant' => '1'])->assertForbidden();
        // Новый сотрудник: настройки по умолчанию Пн–Сб 09:00–19:00 / 60
        $this->actingAsUser($this->hr)->post('/team/employees', ['full_name' => 'New Meet', 'email' => 'new.meet@fluggi.test', 'role_code' => 'MANAGER',
            'meeting_participant' => '1', 'meeting_days' => ['1', '2', '3', '4', '5', '6'], 'meeting_from' => '09:00', 'meeting_to' => '19:00', 'meeting_slot' => '60'])->assertSessionHasNoErrors();
        $new = User::where('email', 'new.meet@fluggi.test')->firstOrFail();
        $this->assertSame(1, (int) $new->meeting_participant);
        $this->assertEquals(['days' => [1, 2, 3, 4, 5, 6], 'from' => '09:00', 'to' => '19:00', 'slot' => 60], json_decode($new->meeting_slots, true));
    }

    public function test_slots_show_free_and_busy_with_occupant_and_respect_settings(): void
    {
        $m = $this->book($this->manager, [$this->manager], self::MONDAY.'T10:00');
        $grid = $this->slots($this->manager, self::MONDAY, [$this->manager, $this->second]);

        $one = $grid[$this->manager->id];
        $this->assertSame(['09:00', '10:00', '11:00'], array_column($one['slots'], 'time'), '09:00–12:00 по 60 минут');
        $this->assertSame('free', self::slot($one, '09:00')['state']);
        $busy = self::slot($one, '10:00');
        $this->assertSame('busy', $busy['state']);
        $this->assertSame(['Manager One'], $busy['meetings'][0]['participants']);
        $this->assertSame('10:00–11:00', $busy['meetings'][0]['time']);
        $this->assertTrue($busy['meetings'][0]['visible']);
        $this->assertStringContainsString($this->lead->title, $busy['meetings'][0]['subject']);
        $this->assertSame(self::MONDAY.'T09:00', self::slot($one, '09:00')['value']);
        $this->assertSame(60, self::slot($one, '09:00')['minutes']);

        // Второй участник: слоты по 30 минут до 10:30; в 10:00 занят первый — слот не общий
        $two = $grid[$this->second->id];
        $this->assertSame(['09:00', '09:30', '10:00'], array_column($two['slots'], 'time'));
        $this->assertSame('free', self::slot($two, '10:00')['state']);
        $this->assertSame(['Manager One'], self::slot($two, '10:00')['conflicts']);
        $this->assertSame([], self::slot($two, '09:30')['conflicts']);

        // Суббота — у второго нерабочий день
        $sat = $this->slots($this->manager, self::SATURDAY, [$this->manager, $this->second]);
        $this->assertTrue($sat[$this->second->id]['dayOff']);
        $this->assertSame([], $sat[$this->second->id]['slots']);
        $this->assertCount(3, $sat[$this->manager->id]['slots']);

        // Редактируемая встреча не занимает своё место; отменённая — тоже
        $this->assertSame('free', self::slot($this->slots($this->manager, self::MONDAY, [$this->manager], $m->id)[$this->manager->id], '10:00')['state']);
        // Чужой менеджер видит, кем занято, но не лид
        $other = $this->slots($this->otherManager, self::MONDAY, [$this->manager]);
        $hidden = self::slot($other[$this->manager->id], '10:00')['meetings'][0];
        $this->assertFalse($hidden['visible']);
        $this->assertNull($hidden['subject']);
        $this->assertSame(['Manager One'], $hidden['participants']);
        $this->actingAsUser($this->otherManager)->getJson('/sales/meetings/slots?date='.self::MONDAY.'&exclude='.$m->id)->assertNotFound();

        $this->actingAsUser($this->manager)->put("/sales/meetings/{$m->id}", ['status' => 'CANCELLED'])->assertSessionHasNoErrors();
        $this->assertSame('free', self::slot($this->slots($this->manager, self::MONDAY, [$this->manager])[$this->manager->id], '10:00')['state']);

        // Сегодняшние прошедшие слоты недоступны
        $today = CarbonImmutable::now('Asia/Tashkent');
        $this->flag($this->manager, ['days' => [1, 2, 3, 4, 5, 6, 7], 'from' => '00:00', 'to' => '23:45', 'slot' => 15]);
        $row = $this->slots($this->manager, $today->toDateString(), [$this->manager])[$this->manager->id];
        if ($today->format('H:i') >= '00:15') {
            $this->assertSame('past', $row['slots'][0]['state']);
        }
        if ($today->format('H:i') < '23:30') {
            $this->assertSame('free', end($row['slots'])['state']);
        }
    }

    public function test_conflicting_meeting_is_rejected_with_participant_name(): void
    {
        $this->book($this->manager, [$this->manager, $this->second], self::MONDAY.'T10:00');
        // Пересечение 10:30–11:30 у второго участника
        $res = $this->actingAsUser($this->rop)->postJson('/sales/meetings', [
            'lead_id' => $this->lead->id, 'starts_at' => self::MONDAY.'T10:30', 'duration_min' => 60, 'type' => 'ONLINE', 'participant_ids' => [$this->second->id],
        ])->assertStatus(422);
        $this->assertStringContainsString('Meet Second', $res->json('message'));
        $this->assertStringContainsString('10:00–11:00', $res->json('message'));
        $this->assertArrayHasKey('starts_at', $res->json('errors'));
        $this->assertSame(1, Meeting::count());
        // Сразу после — можно
        $this->book($this->rop, [$this->second], self::MONDAY.'T11:00');
        // Перенос на занятое время — тоже отказ
        $later = Meeting::where('starts_at', CarbonImmutable::parse(self::MONDAY.' 11:00', 'Asia/Tashkent')->utc())->firstOrFail();
        $this->actingAsUser($this->rop)->putJson("/sales/meetings/{$later->id}", ['starts_at' => self::MONDAY.'T10:15'])->assertStatus(422);
        // Участник без отметки «Участвует во встречах» — ошибка; ответственный не из участников — ошибка
        $this->actingAsUser($this->manager)->post('/sales/meetings', ['lead_id' => $this->lead->id, 'starts_at' => self::MONDAY.'T15:00', 'type' => 'PHONE', 'participant_ids' => [$this->otherManager->id]])
            ->assertSessionHasErrors('participant_ids');
        $this->actingAsUser($this->manager)->post('/sales/meetings', ['lead_id' => $this->lead->id, 'starts_at' => self::MONDAY.'T15:00', 'type' => 'PHONE', 'participant_ids' => [$this->second->id], 'manager_id' => $this->manager->id])
            ->assertSessionHasErrors('manager_id');
    }

    public function test_responsible_writes_result_participants_only_view(): void
    {
        // Создатель — ведущий лида, ответственный за результат — второй участник
        $m = $this->book($this->manager, [$this->manager, $this->second], self::MONDAY.'T09:00', 60, ['manager_id' => $this->second->id]);
        $this->assertSame($this->second->id, $m->manager_id);
        $this->assertEqualsCanonicalizing([$this->manager->id, $this->second->id], $m->participantIds());

        // Ответственный по умолчанию — создатель, если он среди участников
        $d = $this->book($this->manager, [$this->second, $this->manager], self::MONDAY.'T11:00');
        $this->assertSame($this->manager->id, $d->manager_id);

        $third = $this->user('MANAGER', $this->team2, ['full_name' => 'Third Party']);
        $this->flag($third, []);
        $v = $this->book($this->manager, [$this->manager, $third], self::MONDAY.'T10:00');
        $this->assertSame($this->manager->id, $v->manager_id);
        // Участник из другого отдела видит встречу, но не пишет результат и не меняет её
        $this->actingAsUser($third)->get('/sales/meetings?view=all')->assertOk()->assertSee($this->lead->title);
        $this->actingAsUser($third)->post("/sales/meetings/{$v->id}/complete", ['result' => 'x'])->assertForbidden();
        $this->actingAsUser($third)->put("/sales/meetings/{$v->id}", ['status' => 'NO_SHOW'])->assertForbidden();
        $this->actingAsUser($third)->put("/sales/meetings/{$v->id}", ['starts_at' => self::MONDAY.'T12:00'])->assertForbidden();
        // Не участник — встречи не видно
        $this->actingAsUser($this->otherManager)->post("/sales/meetings/{$v->id}/complete", ['result' => 'x'])->assertNotFound();

        // Создатель (не ответственный) результат не пишет; ответственный — пишет
        $this->actingAsUser($this->manager)->post("/sales/meetings/{$m->id}/complete", ['result' => 'x'])->assertForbidden();
        $this->actingAsUser($this->second)->post("/sales/meetings/{$m->id}/complete", ['result' => 'Договорились'])->assertSessionHasNoErrors();
        $this->assertSame('DONE', $m->fresh()->status);
        // РОП отдела и CEO могут писать результат по любой встрече своей области
        $this->actingAsUser($this->rop)->put("/sales/meetings/{$d->id}", ['status' => 'NO_SHOW'])->assertSessionHasNoErrors();
        $this->actingAsUser($this->ceo)->post("/sales/meetings/{$v->id}/complete", ['result' => 'ok'])->assertSessionHasNoErrors();

        // Карточка лида показывает участников и ответственного
        $this->actingAsUser($this->manager)->get("/sales/leads/{$this->lead->id}")->assertOk()->assertSee('Meet Second')->assertSee('Third Party');
        // Смена участников и ответственного в аудите
        $n = $this->book($this->manager, [$this->manager], self::MONDAY.'T15:00');
        $this->actingAsUser($this->manager)->put("/sales/meetings/{$n->id}", ['participant_ids' => [$this->manager->id, $this->second->id], 'manager_id' => $this->second->id])->assertSessionHasNoErrors();
        $this->assertSame($this->second->id, $n->fresh()->manager_id);
        $this->assertTrue(AuditLog::where('action', 'meeting.update')->where('entity_id', $n->id)->exists());
    }

    public function test_all_participants_are_notified(): void
    {
        $m = $this->book($this->manager, [$this->manager, $this->second], self::MONDAY.'T09:00');
        $payload = OutboxEvent::where('type', 'meeting.created')->latest('created_at')->firstOrFail()->payload;
        $payload = is_array($payload) ? $payload : json_decode($payload, true);
        $this->assertEqualsCanonicalizing([$this->manager->id, $this->second->id], $payload['participantIds']);

        Outbox::processBatch();
        $this->assertEqualsCanonicalizing([$this->rop->id, $this->second->id], Notification::where('type', 'meeting.created')->pluck('user_id')->all(), 'автор встречи себе не получает');

        $this->actingAsUser($this->manager)->put("/sales/meetings/{$m->id}", ['starts_at' => self::MONDAY.'T11:00'])->assertSessionHasNoErrors();
        $this->actingAsUser($this->manager)->put("/sales/meetings/{$m->id}", ['status' => 'CANCELLED'])->assertSessionHasNoErrors();
        Outbox::processBatch();
        $changed = Notification::where('type', 'meeting.changed')->where('user_id', $this->second->id)->pluck('title')->all();
        $this->assertContains('Встреча перенесена', $changed);
        $this->assertContains('Встреча отменена', $changed);

        // Добавленный участник получает уведомление
        $n = $this->book($this->manager, [$this->manager], self::MONDAY.'T10:00');
        $third = $this->user('ROP', null, ['full_name' => 'Added Later']);
        $this->flag($third, []);
        $this->actingAsUser($this->manager)->put("/sales/meetings/{$n->id}", ['participant_ids' => [$this->manager->id, $third->id]])->assertSessionHasNoErrors();
        Outbox::processBatch();
        $this->assertTrue(Notification::where('user_id', $third->id)->where('type', 'meeting.created')->exists());

        // Напоминания — всем участникам
        $soon = $this->book($this->manager, [$this->manager, $this->second], CarbonImmutable::now('Asia/Tashkent')->addMinutes(20)->format('Y-m-d\TH:i'), 15);
        $this->assertNotNull($soon);
        ReminderService::run();
        $this->assertTrue(Notification::where('type', 'meeting.reminder')->where('user_id', $this->second->id)->exists());
        $this->assertTrue(Notification::where('type', 'meeting.reminder')->where('user_id', $this->manager->id)->exists());
        $done = OutboxEvent::where('type', 'meeting.completed')->exists();
        $this->assertFalse($done);
        $this->actingAsUser($this->manager)->post("/sales/meetings/{$soon->id}/complete", ['result' => 'ok'])->assertSessionHasNoErrors();
        $payload = OutboxEvent::where('type', 'meeting.completed')->firstOrFail()->payload;
        $payload = is_array($payload) ? $payload : json_decode($payload, true);
        $this->assertEqualsCanonicalizing([$this->manager->id, $this->second->id], $payload['participantIds']);
    }

    public function test_old_meetings_are_backfilled_and_stay_editable(): void
    {
        $id = (string) Str::uuid();
        DB::table('meetings')->insert(['id' => $id, 'lead_id' => $this->lead->id, 'manager_id' => $this->otherManager->id, 'team_id' => $this->team2->id,
            'starts_at' => '2030-05-06 05:00:00.000', 'type' => 'OFFLINE', 'created_by_id' => $this->otherManager->id, 'updated_at' => now()]);
        (require base_path('database/migrations/2026_10_20_000000_meeting_participants.php'))->up();
        (require base_path('database/migrations/2026_10_20_000000_meeting_participants.php'))->up();
        $this->assertSame([$this->otherManager->id], DB::table('meeting_participants')->where('meeting_id', $id)->pluck('user_id')->all());
        // Ответственный без отметки остаётся в участниках и встречу можно менять
        $this->actingAsUser($this->otherManager)->put("/sales/meetings/{$id}", ['participant_ids' => [$this->otherManager->id, $this->second->id], 'comment' => 'x'])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$this->otherManager->id, $this->second->id], Meeting::findOrFail($id)->participantIds());
        // Встреча без выбранных участников — ведущий лида, как раньше
        $legacy = $this->book($this->manager, [], self::MONDAY.'T16:00');
        $this->assertSame([$this->manager->id], $legacy->participantIds());
    }

    public function test_slots_board_and_permissions(): void
    {
        $this->book($this->manager, [$this->manager, $this->second], self::MONDAY.'T09:00');
        $this->actingAsUser($this->otherManager)->get('/sales/meetings/board?date='.self::MONDAY)->assertOk()
            ->assertSee('Manager One')->assertSee('Meet Second')->assertSee('Занято — Manager One, Meet Second')->assertDontSee($this->lead->title);
        $this->actingAsUser($this->manager)->get('/sales/meetings/board?date='.self::MONDAY)->assertOk()->assertSee($this->lead->title);
        $this->actingAsUser($this->executor)->get('/sales/meetings/board')->assertForbidden();
        $this->actingAsUser($this->executor)->getJson('/sales/meetings/slots?date='.self::MONDAY)->assertForbidden();
        $this->actingAsUser($this->hr)->getJson('/sales/meetings/slots?date='.self::MONDAY)->assertForbidden();
        $this->actingAsUser($this->manager)->getJson('/sales/meetings/slots?date=bad')->assertStatus(422);
        // Форма назначения встречи на карточке лида — с участниками и слотами
        $this->actingAsUser($this->manager)->get("/sales/leads/{$this->lead->id}")->assertOk()->assertSee('meetingPlanner', false)->assertSee('Выберите участников и дату');
    }
}
