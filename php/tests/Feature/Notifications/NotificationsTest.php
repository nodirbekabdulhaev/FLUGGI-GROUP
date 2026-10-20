<?php

namespace Tests\Feature\Notifications;

use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationSetting;
use App\Models\Task;
use App\Support\Lang;
use App\Support\Outbox;
use App\Support\Settings;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use CommsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures();
    }

    public function test_lead_created_notifies_owner_and_ceo_in_their_language(): void
    {
        $this->ceo->update(['locale' => 'uz']);
        $lead = $this->lead($this->manager, ['title' => 'Alfa']);
        Outbox::publish('lead.created', ['leadId' => $lead->id, 'ownerId' => $this->manager->id, 'teamId' => $this->team1->id, 'createdById' => $this->manager->id, 'budgetUzs' => null], $this->manager->id);
        $this->drain();
        // Сам себе — не уведомляется (автор события = ответственный)
        $this->assertSame(0, Notification::where('user_id', $this->manager->id)->count());
        $ceo = Notification::where('user_id', $this->ceo->id)->firstOrFail();
        $this->assertSame('Yangi lid', $ceo->title);
        $this->assertStringContainsString('Alfa → '.$this->manager->full_name, $ceo->body);
        $this->assertSame('/sales/leads/'.$lead->id, $ceo->link);
    }

    public function test_large_lead_and_deal_go_to_rop_and_ceo(): void
    {
        $lead = $this->lead($this->manager);
        Outbox::publish('lead.created', ['leadId' => $lead->id, 'ownerId' => $this->manager->id, 'teamId' => $this->team1->id, 'createdById' => $this->rop->id, 'budgetUzs' => '60000000'], $this->rop->id);
        $deal = $this->deal($this->client($this->manager), $this->manager, '70000000');
        Outbox::publish('deal.created', ['dealId' => $deal->id, 'ownerId' => $this->manager->id, 'teamId' => $this->team1->id, 'amountUzs' => '70000000'], $this->manager->id);
        $this->drain();
        $this->assertSame(1, Notification::where('user_id', $this->manager->id)->where('type', 'lead.created')->count());
        $this->assertSame(1, Notification::where('user_id', $this->ceo->id)->where('type', 'lead.large')->count());
        $this->assertStringContainsString('60 000 000 UZS', str_replace("\u{00A0}", ' ', Notification::where('type', 'lead.large')->value('body')));
        $this->assertSame(1, Notification::where('user_id', $this->rop->id)->where('type', 'deal.large')->count());
        $this->assertSame(0, Notification::where('user_id', $this->otherRop->id)->count());
        // Порог из настроек
        Settings::save('automation', ['largeAmountUzs' => 100_000_000] + Settings::automation());
        Outbox::publish('deal.created', ['dealId' => $deal->id, 'ownerId' => $this->manager->id, 'teamId' => $this->team1->id, 'amountUzs' => '70000000'], $this->manager->id);
        $this->drain();
        $this->assertSame(1, Notification::where('user_id', $this->rop->id)->where('type', 'deal.created')->count());
    }

    public function test_personal_channel_settings_and_telegram_queue(): void
    {
        config(['fluggi.telegram.token' => '123:test']);
        $this->manager->update(['telegram_chat_id' => '777']);
        NotificationSetting::insert(['user_id' => $this->manager->id, 'event_type' => 'lead.assigned', 'channel' => 'TELEGRAM', 'enabled' => false]);
        $lead = $this->lead($this->manager);
        Outbox::publish('lead.assigned', ['leadId' => $lead->id, 'ownerId' => $this->manager->id, 'previousOwnerId' => null], $this->rop->id);
        Outbox::publish('lead.created', ['leadId' => $lead->id, 'ownerId' => $this->manager->id, 'teamId' => $this->team1->id, 'createdById' => $this->rop->id, 'budgetUzs' => null], $this->rop->id);
        $this->drain();
        $this->assertSame(1, Notification::where('user_id', $this->manager->id)->where('type', 'lead.assigned')->count());
        $this->assertSame(0, NotificationDelivery::where('type', 'lead.assigned')->count());
        $this->assertSame(1, NotificationDelivery::where('user_id', $this->manager->id)->where('type', 'lead.created')->where('status', 'PENDING')->count());
    }

    public function test_settings_list_depends_on_role_and_saves(): void
    {
        $this->actingAsUser($this->manager);
        $this->get('/profile')->assertOk()->assertSee(t('telegram.settingsTitle'))->assertSee(t('notifications.types.lead.created'))
            ->assertDontSee(t('notifications.types.report.weekly'));
        $r = $this->putJson('/notifications/settings', ['settings' => [['eventType' => 'lead.created', 'channel' => 'TELEGRAM', 'enabled' => false], ['eventType' => 'unknown', 'channel' => 'IN_APP', 'enabled' => false]]]);
        $r->assertOk();
        $row = collect($r->json())->firstWhere('type', 'lead.created');
        $this->assertTrue($row['inApp']);
        $this->assertFalse($row['telegram']);
        $this->assertSame(1, NotificationSetting::where('user_id', $this->manager->id)->count());
        $this->putJson('/notifications/settings', ['settings' => [['eventType' => 'lead.created', 'channel' => 'TELEGRAM', 'enabled' => true]]])->assertOk();
        $this->assertTrue((bool) NotificationSetting::where('user_id', $this->manager->id)->value('enabled'));
    }

    public function test_list_unread_and_read_are_personal(): void
    {
        $mine = Notification::create(['user_id' => $this->manager->id, 'type' => 'lead.created', 'title' => 'Мой', 'link' => '/sales/leads/x']);
        $foreign = Notification::create(['user_id' => $this->rop->id, 'type' => 'lead.created', 'title' => 'Чужой']);
        foreach (['ru', 'uz'] as $loc) {
            $this->manager->update(['locale' => $loc]);
            $this->actingAsUser($this->manager)->get('/notifications')->assertOk()->assertSee('Мой')->assertDontSee('Чужой')
                ->assertSee(Lang::get('notifications.title', [], $loc));
        }
        $this->getJson('/notifications/unread-count')->assertExactJson(['count' => 1]);
        $this->postJson('/notifications/'.$foreign->id.'/read')->assertNoContent();
        $this->assertNull($foreign->fresh()->read_at);
        $this->get('/notifications/'.$foreign->id.'/open')->assertNotFound();
        $this->get('/notifications/'.$mine->id.'/open')->assertRedirect('/sales/leads/x');
        $this->assertNotNull($mine->fresh()->read_at);
        Notification::create(['user_id' => $this->manager->id, 'type' => 'x', 'title' => 'Ещё']);
        $this->post('/notifications/read-all')->assertRedirect();
        $this->getJson('/notifications/unread-count')->assertExactJson(['count' => 0]);
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_task_and_project_events(): void
    {
        $s = $this->sale($this->manager);
        $task = Task::create(['project_id' => $s['project']->id, 'title' => 'Дизайн', 'assignee_id' => $this->executor->id, 'creator_id' => $this->manager->id, 'deadline' => now()->addDay()]);
        Outbox::publish('task.assigned', ['taskId' => $task->id, 'assigneeId' => $this->executor->id], $this->manager->id);
        Outbox::publish('task.status_changed', ['taskId' => $task->id, 'projectId' => $s['project']->id, 'from' => 'IN_PROGRESS', 'to' => 'REVIEW'], $this->executor->id);
        Outbox::publish('task.status_changed', ['taskId' => $task->id, 'projectId' => $s['project']->id, 'from' => 'REVIEW', 'to' => 'IN_PROGRESS'], $this->manager->id);
        Outbox::publish('task.overdue', ['taskId' => $task->id, 'overdueDays' => 3], null);
        Outbox::publish('project.status_changed', ['projectId' => $s['project']->id, 'from' => 'IN_PROGRESS', 'to' => 'COMPLETED'], $this->manager->id);
        $this->drain();
        $types = fn ($u) => Notification::where('user_id', $u->id)->pluck('type')->sort()->values()->all();
        $this->assertSame(['task.assigned', 'task.overdue', 'task.returned'], $types($this->executor));
        $this->assertContains('task.review', $types($this->rop));
        $this->assertSame('Просрочено 3 дня', Notification::where('user_id', $this->executor->id)->where('type', 'task.overdue')->value('title'));
        $this->assertSame(1, Notification::where('user_id', $this->ceo->id)->where('type', 'project.completed')->count());
    }
}
