<?php

namespace Tests\Feature\Todos;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\SmmIdea;
use App\Models\SmmIdeaHistory;
use App\Models\Todo;
use App\Models\User;
use App\Support\Lang;
use Tests\Feature\Notifications\CommsFixtures;
use Tests\TestCase;

/**
 * «Мои дела»: поручение любому сотруднику (видят оба, уведомление в CRM и Telegram, права на изменение)
 * и «Поставить задачу» из карточек лида, сделки, клиента и идеи SMM (связь, блок «Задачи», лента, 404).
 */
class TodoAssignTest extends TestCase
{
    use CommsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures();
    }

    public function test_any_active_employee_can_be_assigned_and_both_sides_see_the_todo(): void
    {
        config(['fluggi.telegram.token' => '123:test']);
        $this->otherManager->update(['telegram_chat_id' => '777']);

        // Исполнитель (без права task.create) поручает дело менеджеру другого отдела
        $t = $this->actingAsUser($this->executor)->postJson('/todos', ['title' => 'Подготовить макет', 'owner_id' => $this->otherManager->id])
            ->assertCreated()->json();
        $this->assertSame([$this->otherManager->id, $this->executor->id], [$t['owner']['id'], $t['creator']['id']]);
        $this->assertSame(1, Notification::where('user_id', $this->otherManager->id)->where('type', 'todo.assigned')->count());
        $this->assertSame(1, NotificationDelivery::where('user_id', $this->otherManager->id)->where('type', 'todo.assigned')->where('link', '/todos')->count());
        $this->assertTrue(AuditLog::where('action', 'todo.create')->where('entity_id', $t['id'])->exists());

        // Поручивший видит дело во вкладке «Поручил я», ответственный — в «Мои» и в «Списке дел»
        $this->get('/todos?view=assigned')->assertOk()->assertSee('Подготовить макет');
        $this->get('/todos')->assertOk()->assertDontSee('Подготовить макет');
        $this->actingAsUser($this->otherManager)->get('/todos')->assertOk()->assertSee('Подготовить макет');
        $this->assertSame(['Подготовить макет'], array_column($this->getJson('/todos/dock')->json('todos'), 'title'));
        // «Ответственный» в панели: все активные сотрудники, «я» — первым
        $owners = $this->getJson('/todos/dock')->json('owners');
        $this->assertSame($this->otherManager->id, $owners[0]['id']);
        $this->assertContains($this->otherRop->id, array_column($owners, 'id'));

        // Посторонний не видит и не меняет дело
        $this->actingAsUser($this->manager)->patchJson('/todos/'.$t['id'], ['title' => 'hack'])->assertNotFound();
        $this->postJson('/todos/'.$t['id'].'/complete')->assertNotFound();
        $this->deleteJson('/todos/'.$t['id'])->assertNotFound();

        // Поручивший передаёт другому — уведомление новому ответственному, изменение в аудите
        $this->actingAsUser($this->executor)->patchJson('/todos/'.$t['id'], ['owner_id' => $this->manager->id, 'title' => 'Подготовить макет v2'])->assertOk();
        $this->assertSame(t('todos.notify.transferred'), Notification::where('user_id', $this->manager->id)->where('type', 'todo.assigned')->value('title'));
        $changes = AuditLog::where('action', 'todo.update')->where('entity_id', $t['id'])->value('changes');
        $this->assertSame($this->manager->id, $changes['owner_id']['new']);
        $this->actingAsUser($this->otherManager)->patchJson('/todos/'.$t['id'], ['title' => 'x'])->assertNotFound();

        // Ответственный выполняет; CEO может изменить любое дело; удаляет — автор или CEO
        $this->actingAsUser($this->manager)->postJson('/todos/'.$t['id'].'/complete')->assertOk();
        $this->assertTrue(AuditLog::where('action', 'todo.status')->where('entity_id', $t['id'])->exists());
        $this->assertSame(1, Notification::where('user_id', $this->executor->id)->where('type', 'todo.assigned')->count());
        $this->deleteJson('/todos/'.$t['id'])->assertForbidden();
        $this->actingAsUser($this->ceo)->postJson('/todos/'.$t['id'].'/reopen')->assertOk();
        $this->actingAsUser($this->executor)->deleteJson('/todos/'.$t['id'])->assertNoContent();
        $this->assertTrue(AuditLog::where('action', 'todo.delete')->where('entity_id', $t['id'])->exists());

        // Заблокированному сотруднику поручить нельзя
        $blocked = $this->user('MANAGER', attrs: ['status' => 'BLOCKED']);
        $this->postJson('/todos', ['title' => 'x', 'owner_id' => $blocked->id])->assertNotFound();

        // В формах «Ответственный» — все активные сотрудники, «я» — по умолчанию первым
        $page = $this->get('/todos')->assertOk();
        $page->assertSee($this->otherRop->full_name)->assertDontSee($blocked->full_name);
    }

    public function test_assign_task_from_lead_card(): void
    {
        config(['fluggi.telegram.token' => '123:test']);
        $this->executor->update(['telegram_chat_id' => '555']);
        $lead = $this->lead($this->manager, ['title' => 'Сайт для Alfa']);

        $this->actingAsUser($this->manager)->post('/todos', ['title' => 'Созвониться с клиентом', 'description' => 'Уточнить ТЗ', 'owner_id' => $this->executor->id,
            'priority' => 'HIGH', 'due_at' => now()->addDay()->format('Y-m-d\TH:i'), 'lead_id' => $lead->id, '_back' => "/sales/leads/{$lead->id}#tasks"])
            ->assertSessionHasNoErrors()->assertRedirect("/sales/leads/{$lead->id}#tasks");
        $todo = Todo::where('title', 'Созвониться с клиентом')->firstOrFail();
        $this->assertSame([$lead->id, $this->executor->id, 'HIGH'], [$todo->lead_id, $todo->owner_id, $todo->priority]);

        // Лента лида: «Поставлена задача … для …»; уведомление со ссылкой на лид (в Telegram — тоже)
        $activity = Activity::where('lead_id', $lead->id)->where('type', 'todo.created')->firstOrFail();
        $this->assertSame($this->executor->full_name, $activity->payload['owner']);
        $note = Notification::where('user_id', $this->executor->id)->where('type', 'todo.assigned')->firstOrFail();
        $this->assertSame("/sales/leads/{$lead->id}", $note->link);
        $this->assertStringContainsString('L-', $note->body);
        $this->assertSame("/sales/leads/{$lead->id}", NotificationDelivery::where('user_id', $this->executor->id)->value('link'));

        // Блок «Задачи» и кнопка — на всех языках
        foreach (Lang::LOCALES as $loc) {
            $this->manager->update(['locale' => $loc]);
            $this->actingAsUser($this->manager)->get("/sales/leads/{$lead->id}")->assertOk()
                ->assertSee(Lang::get('todos.assignTask', [], $loc))->assertSee(Lang::get('leads.tabs.tasks', [], $loc))
                ->assertSee('Созвониться с клиентом')->assertSee($this->executor->full_name);
        }
        $this->manager->update(['locale' => 'ru']);
        $this->actingAsUser($this->manager)->get("/sales/leads/{$lead->id}")->assertSee(t('todos.recordLog', ['title' => 'Созвониться с клиентом', 'name' => $this->executor->full_name]));

        // Ответственный видит ссылку на лид в «Моих делах» и отмечает выполнение (лид ему не виден — запись не меняется)
        $this->actingAsUser($this->executor)->get('/todos')->assertOk()->assertSee("/sales/leads/{$lead->id}")->assertSee('Сайт для Alfa');
        $this->patchJson('/todos/'.$todo->id, ['title' => 'Созвониться с клиентом!', 'lead_id' => $lead->id])->assertOk();
        $this->from("/sales/leads/{$lead->id}")->post('/todos/'.$todo->id.'/complete')->assertRedirect("/sales/leads/{$lead->id}");
        $this->assertSame('DONE', $todo->fresh()->status);

        // Чужой лид: 404, дело не создаётся; адрес возврата — только внутри CRM
        $this->actingAsUser($this->otherManager)->post('/todos', ['title' => 'x', 'lead_id' => $lead->id])->assertNotFound();
        $this->actingAsUser($this->manager)->post('/todos', ['title' => 'y', 'lead_id' => $lead->id, '_back' => '//evil.example/x'])->assertRedirect();
        $this->assertNotSame('http://evil.example/x', $this->actingAsUser($this->manager)->post('/todos', ['title' => 'z', '_back' => '//evil.example/x'])->headers->get('Location'));
        $this->assertSame(0, Todo::where('title', 'x')->count());
    }

    public function test_assign_task_from_deal_and_client_cards(): void
    {
        $client = $this->client($this->manager, ['name' => 'Beta LLC']);
        $deal = $this->deal($client, $this->manager, '5000000', ['title' => 'Beta SMM']);

        $this->actingAsUser($this->manager)->post('/todos', ['title' => 'Отправить КП', 'owner_id' => $this->rop->id, 'deal_id' => $deal->id])->assertSessionHasNoErrors();
        $dealTodo = Todo::where('title', 'Отправить КП')->firstOrFail();
        $this->assertSame([$deal->id, $client->id], [$dealTodo->deal_id, $dealTodo->client_id]);
        $this->assertTrue(Activity::where('deal_id', $deal->id)->where('type', 'todo.created')->exists());
        $this->assertSame("/sales/deals/{$deal->id}", Notification::where('user_id', $this->rop->id)->where('type', 'todo.assigned')->value('link'));

        $this->post('/todos', ['title' => 'Поздравить с праздником', 'client_id' => $client->id])->assertSessionHasNoErrors();
        $clientTodo = Todo::where('title', 'Поздравить с праздником')->firstOrFail();
        $this->assertSame([$client->id, $this->manager->id], [$clientTodo->client_id, $clientTodo->owner_id]);
        $this->assertSame(0, Notification::where('user_id', $this->manager->id)->where('type', 'todo.assigned')->count(), 'себе — без уведомления');

        foreach (Lang::LOCALES as $loc) {
            $this->manager->update(['locale' => $loc]);
            $this->actingAsUser($this->manager)->get("/sales/deals/{$deal->id}")->assertOk()->assertSee(Lang::get('todos.assignTask', [], $loc))->assertSee('Отправить КП');
            // Задачи сделки видны и в карточке клиента
            $this->get("/clients/{$client->id}")->assertOk()->assertSee(Lang::get('todos.assignTask', [], $loc))
                ->assertSee('Поздравить с праздником')->assertSee('Отправить КП');
        }

        // Чужие сделка и клиент — 404
        $this->actingAsUser($this->otherManager)->post('/todos', ['title' => 'x', 'deal_id' => $deal->id])->assertNotFound();
        $this->post('/todos', ['title' => 'x', 'client_id' => $client->id])->assertNotFound();
        $this->assertSame(0, Todo::where('title', 'x')->count());
    }

    public function test_assign_task_from_smm_idea_card(): void
    {
        $smm = $this->user('EXECUTOR', employee: ['specialty' => 'SMM']);
        $otherSmm = $this->user('EXECUTOR', employee: ['specialty' => 'SMM']);
        $idea = SmmIdea::create(['title' => 'Закулисье съёмки', 'author_id' => $smm->id, 'assignee_id' => $smm->id])->refresh();

        $this->actingAsUser($smm)->post('/todos', ['title' => 'Найти локацию', 'owner_id' => $this->ceo->id, 'smm_idea_id' => $idea->id, '_back' => "/smm/{$idea->id}#tasks"])
            ->assertSessionHasNoErrors()->assertRedirect("/smm/{$idea->id}#tasks");
        $todo = Todo::where('title', 'Найти локацию')->firstOrFail();
        $this->assertSame([$idea->id, $this->ceo->id], [$todo->smm_idea_id, $todo->owner_id]);
        $history = SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'todo_created')->firstOrFail();
        $this->assertStringContainsString('Найти локацию', $history->comment);
        $this->assertSame("/smm/{$idea->id}", Notification::where('user_id', $this->ceo->id)->where('type', 'todo.assigned')->value('link'));

        foreach (Lang::LOCALES as $loc) {
            $smm->update(['locale' => $loc]);
            $this->actingAsUser($smm)->get("/smm/{$idea->id}")->assertOk()
                ->assertSee(Lang::get('todos.tasksBlock', [], $loc))->assertSee(Lang::get('todos.assignTask', [], $loc))
                ->assertSee('Найти локацию')->assertSee(Lang::get('smm.history.todo_created', [], $loc));
        }
        $this->actingAsUser($this->ceo)->get('/todos')->assertOk()->assertSee("/smm/{$idea->id}");

        // Идея не видна: другой SMM-специалист и менеджер без раздела SMM — 404
        $this->actingAsUser($otherSmm)->post('/todos', ['title' => 'x', 'smm_idea_id' => $idea->id])->assertNotFound();
        $this->actingAsUser($this->manager)->post('/todos', ['title' => 'x', 'smm_idea_id' => $idea->id])->assertNotFound();
        $this->assertSame(0, Todo::where('title', 'x')->count());
    }

    public function test_record_tasks_block_shows_open_then_done_ordered_by_due(): void
    {
        $lead = $this->lead($this->manager);
        $this->actingAsUser($this->manager);
        foreach (['Позже' => '+3 days', 'Раньше' => '+1 day', 'Готово' => '+2 days'] as $title => $due) {
            $this->post('/todos', ['title' => $title, 'lead_id' => $lead->id, 'due_at' => now()->modify($due)->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        }
        $this->post('/todos/'.Todo::where('title', 'Готово')->value('id').'/complete')->assertRedirect();
        $html = $this->get("/sales/leads/{$lead->id}")->assertOk()->getContent();
        $block = substr($html, strpos($html, 'data-record-tasks'));
        $this->assertTrue(strpos($block, 'Раньше') < strpos($block, 'Позже') && strpos($block, 'Позже') < strpos($block, 'Готово'));
        // Нет права на дело — галочка недоступна
        $other = User::whereKey($this->rop->id)->first();
        $this->actingAsUser($other)->get("/sales/leads/{$lead->id}")->assertOk()->assertSee('disabled aria-label="Раньше"', false);
    }
}
