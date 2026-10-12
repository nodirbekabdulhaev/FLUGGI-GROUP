<?php

namespace Tests\Feature\Projects;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\TaskStatusHistory;
use App\Models\User;
use Tests\TestCase;

/** Проекты, команда, Kanban (ТЗ §19–24) — сценарии прежней версии (projects.int.test.ts). */
class ProjectsTest extends TestCase
{
    use ProjectFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPeople();
    }

    private function smmTemplate(): ProjectTemplate
    {
        return ProjectTemplate::where('name', 'SMM-проект')->firstOrFail();
    }

    public function test_template_team_kanban_review_and_completion(): void
    {
        $p = $this->project();
        $this->actingAsUser($this->rop)->post("/projects/{$p->id}/apply-template", ['template_id' => $this->smmTemplate()->id])->assertSessionHasNoErrors();
        $this->assertSame(8, Task::where('project_id', $p->id)->count());
        // ТЗ §77, Rule 5: исполнитель есть всегда — пока роли нет в команде, задача у РОП
        $this->assertSame([$this->rop->id], Task::where('project_id', $p->id)->distinct()->pluck('assignee_id')->all());
        $this->assertTrue(AuditLog::where('action', 'project.apply_template')->where('entity_id', $p->id)->exists());

        // Назначение исполнителя SMM: задачи шаблона его роли переходят к нему, проект — «Планирование»
        $this->post("/projects/{$p->id}/members", ['user_id' => $this->executor->id, 'role' => 'SMM', 'workload_pct' => 50])->assertSessionHasNoErrors();
        $this->assertSame('PLANNING', $p->fresh()->status);
        $this->assertSame(3, Task::where('project_id', $p->id)->where('assignee_id', $this->executor->id)->count());
        $this->assertTrue(OutboxEvent::where('type', 'project.member_added')->exists());
        // Повторно — нельзя
        $this->post("/projects/{$p->id}/members", ['user_id' => $this->executor->id])->assertSessionHas('error');

        // Исполнитель видит проект, но не деньги и не сделку; видит только свои задачи
        $ex = $this->actingAsUser($this->executor);
        $ex->get("/projects/{$p->id}")->assertOk()->assertDontSee('6 000 000')->assertSee('Контент-план');
        $this->post('/tasks', ['project_id' => $p->id, 'title' => 'Своя', 'assignee_id' => $this->executor->id])->assertForbidden();

        // Менеджер проекта создаёт задачу исполнителю; ответственный — только из команды
        $this->actingAsUser($this->manager)->post('/tasks', ['project_id' => $p->id, 'title' => 'Согласовать рубрики', 'assignee_id' => $this->executor->id, 'priority' => 'HIGH'])->assertSessionHasNoErrors();
        $this->post('/tasks', ['project_id' => $p->id, 'title' => 'x', 'assignee_id' => $this->otherManager->id])->assertSessionHasErrors('assignee_id');
        $created = Task::where('title', 'Согласовать рубрики')->firstOrFail();
        $this->assertMatchesRegularExpression('/^T-\d{5}$/', \App\Services\Projects\TaskService::code($created));
        $this->assertTrue(OutboxEvent::where('type', 'task.assigned')->get()->contains(fn ($e) => $e->payload['taskId'] === $created->id));

        // Исполнитель берёт задачу в работу → проект «В работе»
        $plan = Task::where('project_id', $p->id)->where('title', 'Контент-план')->firstOrFail();
        $this->actingAsUser($this->executor)->postJson("/tasks/{$plan->id}/move", ['status' => 'IN_PROGRESS'])->assertOk();
        $this->assertNotNull($plan->fresh()->started_at);
        $this->assertSame('IN_PROGRESS', $p->fresh()->status);
        $this->postJson("/tasks/{$plan->id}/move", ['status' => 'REVIEW'])->assertOk();
        // РОП возвращает с проверки — переделка
        $this->actingAsUser($this->rop)->postJson("/tasks/{$plan->id}/move", ['status' => 'IN_PROGRESS'])->assertOk();
        $this->assertSame(1, $plan->fresh()->rework_count);
        // Исполнитель не отменяет задачу, не трогает чужую и не меняет поля, но меняет прогресс
        $ex = $this->actingAsUser($this->executor);
        $ex->postJson("/tasks/{$plan->id}/move", ['status' => 'CANCELLED'])->assertStatus(422);
        $foreign = Task::where('project_id', $p->id)->where('title', 'Съёмка')->firstOrFail();
        $ex->postJson("/tasks/{$foreign->id}/move", ['status' => 'DONE'])->assertNotFound();
        $ex->patch("/tasks/{$plan->id}", ['title' => 'Иначе'])->assertForbidden();
        $ex->patch("/tasks/{$plan->id}", ['progress_pct' => 60])->assertSessionHasNoErrors();
        $this->assertSame(60, $plan->fresh()->progress_pct);
        $ex->post("/tasks/{$plan->id}/comments", ['body' => 'Готово к проверке'])->assertSessionHasNoErrors();
        $ex->get("/projects/{$p->id}?task={$plan->id}")->assertOk()->assertSee('Готово к проверке');
        $this->assertSame(['IN_PROGRESS', 'REVIEW', 'IN_PROGRESS', 'TODO'], TaskStatusHistory::where('task_id', $plan->id)->orderByDesc('created_at')->orderByDesc('id')->get()->sortByDesc('created_at')->pluck('to_status')->values()->all());

        // Порядок в колонке: задача встаёт перед первой карточкой
        $this->actingAsUser($this->rop)->post('/tasks', ['project_id' => $p->id, 'title' => 'Срочная правка', 'assignee_id' => $this->rop->id]);
        $urgent = Task::where('title', 'Срочная правка')->firstOrFail();
        $firstTodo = Task::where('project_id', $p->id)->where('status', 'TODO')->orderBy('sort_order')->first();
        $this->postJson("/tasks/{$urgent->id}/move", ['status' => 'TODO', 'before_id' => $firstTodo->id])->assertOk();
        $this->assertSame('Срочная правка', Task::where('project_id', $p->id)->where('status', 'TODO')->orderBy('sort_order')->first()->title);

        // Завершить проект можно, только когда открытых задач нет
        $this->post("/projects/{$p->id}/status", ['status' => 'COMPLETED'])->assertSessionHas('error');
        $this->assertNotSame('COMPLETED', $p->fresh()->status);
        foreach (Task::where('project_id', $p->id)->get() as $t) {
            $this->postJson("/tasks/{$t->id}/move", ['status' => 'DONE'])->assertOk();
        }
        $this->post("/projects/{$p->id}/status", ['status' => 'COMPLETED'])->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame('COMPLETED', $p->status);
        $this->assertNotNull($p->completed_at);
        $this->assertSame('DONE', ProjectMember::where('project_id', $p->id)->first()->status);
        // Закрытый проект: задачи не меняются
        $this->post('/tasks', ['project_id' => $p->id, 'title' => 'Ещё', 'assignee_id' => $this->rop->id])->assertSessionHas('error');
        $types = Activity::where('project_id', $p->id)->pluck('type')->unique()->all();
        foreach (['project.template_applied', 'project.member_added', 'task.status_changed', 'task.created', 'project.status_changed'] as $type) {
            $this->assertContains($type, $types);
        }
        $this->get("/projects/{$p->id}?tab=history")->assertOk()->assertSee(t('crm.activity.project.member_added'));
    }

    public function test_scopes_status_rules_and_rop_change(): void
    {
        $p = $this->project();
        $this->actingAsUser($this->otherManager)->get("/projects/{$p->id}")->assertNotFound();
        $this->get('/projects/all')->assertOk()->assertDontSee('SMM для Aziz');
        $this->get('/tasks')->assertOk();

        $this->actingAsUser($this->manager)->get('/projects/all')->assertOk()->assertSee('SMM для Aziz');
        $this->post("/projects/{$p->id}/status", ['status' => 'PAUSED'])->assertForbidden();
        $this->post("/projects/{$p->id}/members", ['user_id' => $this->executor->id])->assertForbidden();
        // Сменить РОП проекта может только CEO
        $this->actingAsUser($this->rop)->patch("/projects/{$p->id}", ['rop_id' => $this->ceo->id])->assertForbidden();
        $this->actingAsUser($this->ceo)->patch("/projects/{$p->id}", ['rop_id' => $this->ceo->id])->assertSessionHasNoErrors();
        $this->assertSame($this->ceo->id, $p->fresh()->rop_id);
        $this->patch("/projects/{$p->id}", ['start_date' => '2026-10-10', 'deadline' => '2026-10-01'])->assertSessionHasErrors('deadline');
        $this->post("/projects/{$p->id}/status", ['status' => 'CANCELLED'])->assertSessionHasErrors('comment');
        $this->post("/projects/{$p->id}/status", ['status' => 'CANCELLED', 'comment' => 'Клиент отказался'])->assertSessionHasNoErrors();
        $this->assertSame('CANCELLED', $p->fresh()->status);
        $this->get('/projects/active')->assertOk()->assertDontSee('SMM для Aziz');
        $this->assertSame($p->id, AuditLog::where('action', 'project.status')->first()->entity_id);
        $this->assertTrue(OutboxEvent::where('type', 'project.status_changed')->exists());
    }

    public function test_overdue_views(): void
    {
        $p = $this->project();
        $this->actingAsUser($this->rop)->post("/projects/{$p->id}/members", ['user_id' => $this->executor->id]);
        $this->post('/tasks', ['project_id' => $p->id, 'title' => 'Просроченная', 'assignee_id' => $this->executor->id,
            'deadline' => now()->setTimezone('Asia/Tashkent')->subHours(2)->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->post('/tasks', ['project_id' => $p->id, 'title' => 'Будущая', 'assignee_id' => $this->executor->id,
            'deadline' => now()->setTimezone('Asia/Tashkent')->addDays(20)->format('Y-m-d\TH:i')]);
        $this->get('/projects/overdue')->assertOk()->assertSee('SMM для Aziz');
        $ex = $this->actingAsUser($this->executor);
        $ex->get('/tasks?view=overdue')->assertOk()->assertSee('Просроченная')->assertDontSee('Будущая');
        $ex->get('/tasks?view=today')->assertOk()->assertSee('Просроченная');
        $task = Task::where('title', 'Просроченная')->first();
        $ex->postJson("/tasks/{$task->id}/move", ['status' => 'DONE'])->assertOk();
        $ex->get('/tasks?view=overdue')->assertOk()->assertDontSee('Просроченная');
    }

    public function test_templates_settings_apply_and_member_removal(): void
    {
        $p = $this->project(['start_date' => '2026-10-01']);
        $tpl = ['name' => 'Фотосессия', 'tasks' => [
            ['title' => 'Съёмка', 'role' => 'PHOTOGRAPHER', 'start_offset_days' => 1, 'duration_days' => 1, 'priority' => 'MEDIUM'],
            ['title' => 'Ретушь', 'role' => 'DESIGNER', 'start_offset_days' => 2, 'duration_days' => 3, 'priority' => 'HIGH'],
        ], 'is_active' => '1'];
        $this->actingAsUser($this->rop)->post('/settings/templates', $tpl)->assertForbidden();
        $this->get('/settings/templates')->assertForbidden();
        $this->actingAsUser($this->ceo)->post('/settings/templates', $tpl)->assertSessionHasNoErrors();
        $this->post('/settings/templates', ['name' => 'Пусто', 'tasks' => []])->assertSessionHasErrors('tasks');
        foreach (['ru', 'uz'] as $locale) {
            $this->ceo->update(['locale' => $locale]);
            $this->get('/settings/templates')->assertOk()->assertSee('Фотосессия');
        }
        $template = ProjectTemplate::where('name', 'Фотосессия')->firstOrFail();
        $this->assertSame(2, $template->tasks()->count());
        $this->assertTrue(AuditLog::where('action', 'project_template.create')->exists());

        $this->actingAsUser($this->rop)->post("/projects/{$p->id}/apply-template", ['template_id' => $template->id])->assertSessionHasNoErrors();
        $retouch = Task::where('title', 'Ретушь')->firstOrFail();
        $this->assertSame('2026-10-03', $retouch->start_date->format('Y-m-d'));
        // дедлайн: старт + 2 + (3 − 1) дня, 18:00 по Ташкенту (13:00 UTC)
        $this->assertSame('2026-10-05 13:00', $retouch->deadline->utc()->format('Y-m-d H:i'));

        $designer = $this->user('EXECUTOR', employee: ['specialty' => 'DESIGNER']);
        $this->post("/projects/{$p->id}/members", ['user_id' => $designer->id, 'role' => 'DESIGNER']);
        $this->assertSame($designer->id, $retouch->fresh()->assignee_id);
        $member = ProjectMember::where('project_id', $p->id)->where('user_id', $designer->id)->first();
        $this->patch("/projects/{$p->id}/members/{$member->id}", ['status' => 'REMOVED'])->assertSessionHasNoErrors();
        $this->assertSame($this->rop->id, $retouch->fresh()->assignee_id);
        // Исключённый участник больше не видит проект
        $this->actingAsUser($designer)->get("/projects/{$p->id}")->assertNotFound();
    }

    public function test_project_manager_sees_only_projects_of_directions(): void
    {
        $media = \App\Models\Direction::where('code', 'MEDIA')->first();
        $this->actingAsUser($this->ceo)->post('/team/employees', ['email' => 'pm@test.uz', 'full_name' => 'Проект Менеджер', 'role_code' => 'PROJECT_MANAGER', 'direction_ids' => [$media->id]])->assertSessionHasNoErrors()->assertSessionMissing('error');
        $pm = User::where('email', 'pm@test.uz')->firstOrFail();
        $smm = $this->project(['name' => 'SMM для кафе'], 'MEDIA');
        $site = $this->project(['name' => 'Сайт для клиники'], 'IT');

        $this->actingAsUser($pm)->get('/projects/all')->assertOk()->assertSee('SMM для кафе')->assertDontSee('Сайт для клиники');
        $this->get("/projects/{$site->id}")->assertNotFound();
        $this->patch("/projects/{$smm->id}", ['deadline' => '2026-12-31'])->assertSessionHasNoErrors();
        $this->patch("/projects/{$smm->id}", ['direction_id' => null])->assertForbidden();
        $this->post("/projects/{$smm->id}/members", ['user_id' => $this->executor->id])->assertSessionHasNoErrors();
        $this->post('/tasks', ['project_id' => $smm->id, 'title' => 'Контент-план', 'assignee_id' => $this->executor->id])->assertSessionHasNoErrors();
        $this->post('/tasks', ['project_id' => $site->id, 'title' => 'Вёрстка', 'assignee_id' => $this->executor->id])->assertNotFound();
        $this->get('/dashboard')->assertOk()->assertSee($media->name);

        $this->actingAsUser($this->ceo)->patch("/projects/{$site->id}", ['direction_id' => $media->id])->assertSessionHasNoErrors();
        $this->actingAsUser($pm)->get('/projects/all')->assertSee('Сайт для клиники');
    }

    public function test_pages_render_for_roles_in_both_languages(): void
    {
        $p = $this->project();
        $this->actingAsUser($this->rop)->post("/projects/{$p->id}/apply-template", ['template_id' => $this->smmTemplate()->id]);
        $task = Task::where('project_id', $p->id)->first();
        foreach ([$this->ceo, $this->rop, $this->manager] as $u) {
            foreach (['ru', 'uz'] as $locale) {
                $u->update(['locale' => $locale]);
                $c = $this->actingAsUser($u);
                foreach (['/projects/all', '/projects/active', '/projects/overdue', '/projects/completed', '/tasks', '/tasks?view=done'] as $url) {
                    $c->get($url)->assertOk();
                }
                foreach (['tasks', 'team', 'files', 'info', 'history', 'finance'] as $tab) {
                    $c->get("/projects/{$p->id}?tab={$tab}")->assertOk()->assertSee('SMM для Aziz');
                }
                $c->get("/projects/{$p->id}?task={$task->id}")->assertOk()->assertSee($task->title);
            }
        }
        $this->actingAsUser($this->executor)->get('/projects/all')->assertOk();
        $this->actingAsUser(User::find($this->manager->id))->get('/projects')->assertRedirect('/projects/all');
        // Исполнитель без права проектов? HR — нет доступа к проектам
        $this->actingAsUser($this->user('HR_ADMIN'))->get('/projects/all')->assertForbidden();
        $this->assertSame(1, Project::count());
    }

    public function test_deal_partial_shows_visible_project_only(): void
    {
        $p = $this->project();
        $this->as($this->manager);
        $html = view('projects.partials.deal-project', ['deal' => $p->deal])->render();
        $this->assertStringContainsString('SMM для Aziz', $html);
        $this->as($this->otherManager);
        $this->assertStringNotContainsString('SMM для Aziz', view('projects.partials.deal-project', ['deal' => $p->deal])->render());
        $this->as($this->manager);
        $this->assertStringContainsString('SMM для Aziz', view('projects.partials.client-projects', ['client' => $p->client])->render());
    }
}
