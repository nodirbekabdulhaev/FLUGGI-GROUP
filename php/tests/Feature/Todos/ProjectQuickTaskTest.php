<?php

namespace Tests\Feature\Todos;

use App\Models\Activity;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Support\Lang;
use Tests\Feature\Projects\ProjectFixtures;
use Tests\TestCase;

/** «Поставить задачу» в карточке проекта: задача в Kanban, ответственный не из команды добавляется в неё (project.assign). */
class ProjectQuickTaskTest extends TestCase
{
    use ProjectFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPeople();
    }

    public function test_quick_task_adds_member_with_assign_right_only(): void
    {
        $p = $this->project(['status' => 'IN_PROGRESS']);
        $this->assertFalse(ProjectMember::where('project_id', $p->id)->where('user_id', $this->executor->id)->exists());

        // РОП проекта (project.assign): исполнитель не в команде — добавляется, задача ставится ему
        $this->actingAsUser($this->rop)->post('/tasks', ['_form' => 'project-quick-task', 'project_id' => $p->id, 'add_member' => '1', 'title' => 'Снять ролик',
            'description' => 'Для запуска', 'assignee_id' => $this->executor->id, 'priority' => 'HIGH', 'deadline' => now()->addDays(2)->format('Y-m-d\TH:i')])
            ->assertSessionHasNoErrors()->assertRedirect('/projects/'.$p->id);
        $task = Task::where('title', 'Снять ролик')->firstOrFail();
        $this->assertSame([$p->id, $this->executor->id, 'HIGH'], [$task->project_id, $task->assignee_id, $task->priority]);
        $this->assertSame('ACTIVE', ProjectMember::where('project_id', $p->id)->where('user_id', $this->executor->id)->value('status'));
        $this->assertSame($this->executor->full_name, Activity::where('task_id', $task->id)->where('type', 'task.created')->firstOrFail()->payload['assignee']);
        // Исполнитель видит задачу в проекте
        $this->actingAsUser($this->executor)->get('/projects/'.$p->id)->assertOk()->assertSee('Снять ролик');

        // Менеджер проекта без права project.assign: только команда проекта
        $outsider = $this->user('EXECUTOR', attrs: ['full_name' => 'Executor Two']);
        $this->actingAsUser($this->manager)->post('/tasks', ['_form' => 'project-quick-task', 'project_id' => $p->id, 'add_member' => '1', 'title' => 'Чужому',
            'assignee_id' => $outsider->id])->assertSessionHasErrors('assignee_id');
        $this->assertFalse(Task::where('title', 'Чужому')->exists());
        $this->assertFalse(ProjectMember::where('project_id', $p->id)->where('user_id', $outsider->id)->exists());
        $this->post('/tasks', ['project_id' => $p->id, 'add_member' => '1', 'title' => 'Себе', 'assignee_id' => $this->manager->id])->assertSessionHasNoErrors();

        // Проект не виден — 404; без task.create — 403
        $this->actingAsUser($this->otherManager)->post('/tasks', ['project_id' => $p->id, 'add_member' => '1', 'title' => 'x', 'assignee_id' => $this->otherManager->id])->assertNotFound();
        $this->actingAsUser($this->executor)->post('/tasks', ['project_id' => $p->id, 'add_member' => '1', 'title' => 'x', 'assignee_id' => $this->executor->id])->assertForbidden();
        $this->assertFalse(Task::where('title', 'x')->exists());
    }

    public function test_project_card_shows_button_on_top_in_all_languages(): void
    {
        $p = $this->project(['status' => 'IN_PROGRESS']);
        foreach (Lang::LOCALES as $loc) {
            $this->rop->update(['locale' => $loc]);
            foreach (['tasks', 'team', 'info', 'history'] as $tab) {
                $html = $this->actingAsUser($this->rop)->get("/projects/{$p->id}?tab={$tab}")->assertOk()
                    ->assertSee(Lang::get('todos.assignTask', [], $loc))->assertSee('project-quick-task')->getContent();
                // Кандидаты не из команды — в отдельной группе списка
                $this->assertStringContainsString('Executor One', $html);
            }
        }
        // Исполнитель команды без task.create — кнопки нет
        ProjectMember::create(['project_id' => $p->id, 'user_id' => $this->executor->id, 'status' => 'ACTIVE', 'assigned_by_id' => $this->rop->id, 'assigned_at' => now()]);
        $this->actingAsUser($this->executor)->get("/projects/{$p->id}")->assertOk()->assertDontSee('project-quick-task');
    }
}
