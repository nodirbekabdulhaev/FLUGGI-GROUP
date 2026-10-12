<?php

namespace Tests\Feature\Automation;

use App\Models\Notification;
use App\Models\RecurringTodo;
use App\Models\Todo;
use App\Services\Automation\ReminderService;
use App\Services\Automation\Scheduler;
use App\Services\Todos\RecurringTodoService;
use App\Support\Lang;
use Carbon\CarbonImmutable;
use Tests\Feature\Notifications\CommsFixtures;
use Tests\TestCase;

class TodosTest extends TestCase
{
    use CommsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures();
    }

    public function test_own_todo_assignment_client_link_completion_and_rights(): void
    {
        // Любой сотрудник ведёт дела себе — даже без прав на проектные задачи
        $own = $this->actingAsUser($this->executor)->postJson('/todos', ['title' => 'Купить SSD', 'kind' => 'TASK'])->assertCreated()->json();
        $this->assertSame(['OPEN', $this->executor->id, true], [$own['status'], $own['owner']['id'], $own['can']['update']]);
        $this->assertMatchesRegularExpression('/^TD-\d{5}$/', $own['number']);

        // РОП поручает менеджеру своего отдела, с клиентом; чужому отделу — нельзя
        $client = $this->client($this->manager, ['name' => 'Alfa']);
        $t = $this->actingAsUser($this->rop)->postJson('/todos', ['title' => 'Позвонить клиенту', 'kind' => 'CALL', 'priority' => 'HIGH',
            'due_at' => now()->addDay()->format('Y-m-d\TH:i'), 'owner_id' => $this->manager->id, 'client_id' => $client->id])->assertCreated()->json();
        $this->assertSame(['id' => $client->id, 'name' => 'Alfa'], $t['client']);
        $this->assertSame(1, Notification::where('user_id', $this->manager->id)->where('type', 'todo.assigned')->count());
        $this->postJson('/todos', ['title' => 'x', 'owner_id' => $this->otherManager->id])->assertForbidden();
        $this->actingAsUser($this->manager)->postJson('/todos', ['title' => 'x', 'owner_id' => $this->rop->id])->assertForbidden();
        // Клиент, которого не видишь, не привязать
        $foreign = $this->client($this->otherManager);
        $this->postJson('/todos', ['title' => 'x', 'client_id' => $foreign->id])->assertNotFound();

        // «Список дел» менеджера: только его личные открытые дела
        $this->assertSame(['Позвонить клиенту'], array_column($this->getJson('/todos/dock')->json('todos'), 'title'));
        $this->actingAsUser($this->executor)->patchJson('/todos/'.$t['id'], ['title' => 'hack'])->assertNotFound();
        $this->actingAsUser($this->manager)->get('/todos?view=all')->assertForbidden();
        // «Все» у РОП — только сотрудники его отдела
        $this->actingAsUser($this->ceo)->postJson('/todos', ['title' => 'Дело другого отдела', 'owner_id' => $this->otherManager->id])->assertCreated();
        $this->actingAsUser($this->rop)->get('/todos?view=all')->assertOk()->assertSee('Позвонить клиенту')->assertDontSee('Дело другого отдела');

        // Выполнение — РОП получает уведомление; повторно — 422; удалить может только автор
        $done = $this->actingAsUser($this->manager)->postJson('/todos/'.$t['id'].'/complete')->assertOk()->json();
        $this->assertSame('DONE', $done['status']);
        $this->assertNotNull($done['completedAt']);
        $this->assertSame(1, Notification::where('user_id', $this->rop->id)->where('type', 'todo.assigned')->count());
        $this->postJson('/todos/'.$t['id'].'/complete')->assertStatus(422);
        $this->deleteJson('/todos/'.$t['id'])->assertForbidden();
        $this->actingAsUser($this->rop)->deleteJson('/todos/'.$t['id'])->assertNoContent();
        $this->assertSoftDeleted('todos', ['id' => $t['id']]);
        $this->actingAsUser($this->ceo)->get('/todos?view=all&status=OPEN')->assertOk()->assertSee('Купить SSD')->assertSee('Дело другого отдела');
    }

    public function test_reassign_and_reopen(): void
    {
        $t = $this->actingAsUser($this->rop)->postJson('/todos', ['title' => 'Отчёт'])->json();
        $this->patchJson('/todos/'.$t['id'], ['owner_id' => $this->manager->id, 'title' => 'Отчёт за неделю'])->assertOk()->assertJsonPath('owner.id', $this->manager->id);
        $this->assertSame(t('todos.notify.transferred'), Notification::where('user_id', $this->manager->id)->value('title'));
        $this->actingAsUser($this->manager)->post('/todos/'.$t['id'].'/complete')->assertRedirect();
        $this->post('/todos/'.$t['id'].'/reopen')->assertRedirect();
        $this->assertSame('OPEN', Todo::find($t['id'])->status);
    }

    public function test_reminders_today_and_overdue_once(): void
    {
        $this->actingAsUser($this->manager)->postJson('/todos', ['title' => 'Отправить счёт', 'due_at' => now()->subHour()->setTimezone('Asia/Tashkent')->format('Y-m-d\TH:i')])->assertCreated();
        $at10 = CarbonImmutable::parse(now('Asia/Tashkent')->format('Y-m-d').' 10:00', 'Asia/Tashkent')->utc();
        $now = $at10->gt(now()) ? $at10 : CarbonImmutable::now();
        $this->assertSame(1, ReminderService::run($now)['todo.overdue'] ?? null);
        $this->assertArrayNotHasKey('todo.overdue', ReminderService::run($now));
        $this->assertSame('⏰ Просрочено: Отправить счёт', Notification::where('type', 'todo.overdue')->value('title'));
    }

    public function test_tax_calendar_recurring_generation_without_duplicates(): void
    {
        $this->actingAsUser($this->manager)->post('/recurring-todos/tax-calendar')->assertForbidden();
        $this->actingAsUser($this->ceo)->postJson('/recurring-todos/tax-calendar')->assertOk()->assertJsonCount(8);
        $this->postJson('/recurring-todos/tax-calendar')->assertJsonCount(8);
        $turnover = RecurringTodo::where('title', 'like', 'Налог с оборота%')->firstOrFail();
        $this->assertSame(['MONTHLY', 4, 3, 'PAYMENT'], [$turnover->frequency, $turnover->day_of_month, $turnover->remind_days_before, $turnover->kind]);

        $oct1 = CarbonImmutable::parse('2026-10-01T03:00:00Z');
        $this->assertSame(5, RecurringTodoService::generate($oct1)['created']);
        $this->assertSame([
            'Квартальный отчёт IT-Park за III квартал 2026',
            'Налог на доходы работников (НДФЛ) за сентябрь 2026',
            'Налог с оборота за сентябрь 2026',
            'Отчёт IT-Park об обороте за сентябрь 2026',
            'Статотчёт за III квартал 2026',
        ], Todo::where('owner_id', $this->ceo->id)->orderBy('title')->pluck('title')->all());
        // Срок — 4 октября 18:00 по Ташкенту
        $this->assertSame('2026-10-04 13:00:00', Todo::where('owner_id', $this->ceo->id)->first()->due_at->format('Y-m-d H:i:s'));
        $this->assertSame(5, Notification::where('user_id', $this->ceo->id)->where('type', 'todo.recurring')->count());
        $this->assertSame(0, RecurringTodoService::generate($oct1)['created']);
        $this->assertSame(1, RecurringTodoService::generate(CarbonImmutable::parse('2026-10-07T03:00:00Z'))['created']);
        RecurringTodoService::generate(CarbonImmutable::parse('2026-11-28T03:00:00Z'));
        $this->assertSame(1, Todo::where('title', 'Баланс (форма №1) и отчёт о финансовых результатах (форма №2) за 2026')->count());

        // Своё правило; выключенное — не создаёт дел; чужие правила не видны
        $own = $this->postJson('/recurring-todos', ['title' => 'Оплатить аренду за {месяц}', 'kind' => 'PAYMENT', 'frequency' => 'MONTHLY', 'day_of_month' => 25, 'remind_days_before' => 2])->assertCreated()->json();
        $this->putJson('/recurring-todos/'.$own['id'], ['title' => $own['title'], 'frequency' => 'MONTHLY', 'day_of_month' => 25, 'remind_days_before' => 2, 'is_active' => false])->assertOk();
        $this->assertSame(0, RecurringTodoService::generate(CarbonImmutable::parse('2026-10-23T03:00:00Z'))['created']);
        $this->actingAsUser($this->manager)->get('/todos?view=recurring')->assertOk()->assertSee(t('todos.noRules'));
        $this->deleteJson('/recurring-todos/'.$own['id'])->assertNotFound();
        $this->actingAsUser($this->ceo)->postJson('/recurring-todos', ['title' => 'x', 'frequency' => 'QUARTERLY', 'day_of_month' => 4, 'month' => 5])->assertStatus(422);
        $this->postJson('/recurring-todos', ['title' => 'x', 'frequency' => 'MONTHLY', 'day_of_month' => 4, 'owner_id' => $this->manager->id])->assertCreated();
        $this->actingAsUser($this->rop)->postJson('/recurring-todos', ['title' => 'x', 'frequency' => 'MONTHLY', 'day_of_month' => 4, 'owner_id' => $this->manager->id])->assertForbidden();
        $this->assertNull(Scheduler::runNow('recurring-todos')->error);
    }

    public function test_pages_render_in_both_languages(): void
    {
        $this->actingAsUser($this->rop)->postJson('/todos', ['title' => 'Видимое дело', 'owner_id' => $this->manager->id]);
        foreach (['ru', 'uz'] as $loc) {
            $this->rop->update(['locale' => $loc]);
            foreach (['mine', 'assigned', 'all', 'recurring'] as $v) {
                $this->actingAsUser($this->rop)->get('/todos?view='.$v)->assertOk()->assertSee(Lang::get('todos.pageTitle', [], $loc));
            }
        }
        $this->actingAsUser($this->rop)->get('/todos?view=assigned')->assertSee('Видимое дело');
    }
}
