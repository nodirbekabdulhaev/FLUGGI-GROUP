<?php

namespace Tests\Feature\Projects;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\OutboxEvent;
use App\Models\Project;
use App\Models\ProjectCostLine;
use App\Models\ProjectMember;
use App\Models\ProjectTemplate;
use App\Models\Task;
use App\Models\User;
use App\Services\Finance\CostLineService;
use App\Services\Smm\SmmAccess;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Несколько позиций у одного человека в проекте: фотограф + видеограф + мобилограф, оплата за каждую отдельно. */
class MemberPositionsTest extends TestCase
{
    use ProjectFixtures;

    private const THREE = ['PHOTOGRAPHER', 'VIDEOGRAPHER', 'MOBILOGRAPHER'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPeople();
    }

    private function line(Project $p, string $specialty, string $rate, string $label): ProjectCostLine
    {
        return ProjectCostLine::create(['project_id' => $p->id, 'kind' => 'FIXED', 'specialty' => $specialty, 'label' => $label, 'quantity' => '1', 'rate' => $rate, 'currency' => 'UZS']);
    }

    private function member(Project $p, User $u): ProjectMember
    {
        return ProjectMember::where('project_id', $p->id)->where('user_id', $u->id)->firstOrFail();
    }

    public function test_one_person_gets_cost_line_of_each_position_paid_separately(): void
    {
        $p = $this->project();
        $photo = $this->line($p, 'PHOTOGRAPHER', '1500000', 'Фотосъёмка');
        $video = $this->line($p, 'VIDEOGRAPHER', '2500000', 'Видеосъёмка');
        $mobile = $this->line($p, 'MOBILOGRAPHER', '700000', 'Мобилография');
        $design = $this->line($p, 'DESIGNER', '900000', 'Обложки');
        $shooter = $this->user('EXECUTOR', attrs: ['full_name' => 'Shooter'], employee: ['specialty' => 'PHOTOGRAPHER']);

        $this->actingAsUser($this->rop)->post("/projects/{$p->id}/members", ['user_id' => $shooter->id, 'roles' => ['VIDEOGRAPHER', 'PHOTOGRAPHER', 'MOBILOGRAPHER']])
            ->assertSessionHasNoErrors();
        $m = $this->member($p, $shooter);
        $this->assertSame('VIDEOGRAPHER', $m->role); // основная — первая выбранная
        $this->assertSame(['VIDEOGRAPHER', 'PHOTOGRAPHER', 'MOBILOGRAPHER'], $m->positions());
        $this->assertSame('VIDEOGRAPHER,PHOTOGRAPHER,MOBILOGRAPHER', DB::table('project_members')->where('id', $m->id)->value('roles'));
        foreach ([$photo, $video, $mobile] as $l) {
            $this->assertSame($shooter->id, $l->fresh()->assignee_id);
        }
        $this->assertNull($design->fresh()->assignee_id);
        $event = OutboxEvent::where('type', 'project.member_added')->latest('created_at')->firstOrFail();
        $this->assertSame(['VIDEOGRAPHER', 'PHOTOGRAPHER', 'MOBILOGRAPHER'], $event->payload['roles']);
        $audit = AuditLog::where('action', 'project.member_add')->where('entity_id', $p->id)->firstOrFail();
        $this->assertEquals(['old' => null, 'new' => ['VIDEOGRAPHER', 'PHOTOGRAPHER', 'MOBILOGRAPHER']], $audit->changes['roles']);

        // Каждая строка — своя сумма и свой расход на того же исполнителя
        $this->as($this->ceo);
        $amounts = CostLineService::list($p->id)->where('assignee_id', $shooter->id)->pluck('amount', 'specialty')->sortKeys()->all();
        $this->assertSame(['MOBILOGRAPHER' => '700000.00', 'PHOTOGRAPHER' => '1500000.00', 'VIDEOGRAPHER' => '2500000.00'], $amounts);
        foreach ([$photo, $video, $mobile] as $l) {
            $this->actingAsUser($this->ceo)->post("/finance/cost-lines/{$l->id}/accrue")->assertSessionHasNoErrors();
        }
        $paid = Expense::where('project_id', $p->id)->where('payee_user_id', $shooter->id)->orderBy('amount')->pluck('amount')->all();
        $this->assertSame(['700000.00', '1500000.00', '2500000.00'], $paid);

        // Страница команды — все позиции значками
        $this->actingAsUser($this->rop)->get("/projects/{$p->id}?tab=team")->assertOk()
            ->assertSee(t('specialties.PHOTOGRAPHER'))->assertSee(t('specialties.VIDEOGRAPHER'))->assertSee(t('specialties.MOBILOGRAPHER'));
    }

    public function test_removing_position_unassigns_only_planned_lines_and_adding_assigns(): void
    {
        $p = $this->project();
        $photo = $this->line($p, 'PHOTOGRAPHER', '1500000', 'Фотосъёмка');
        $photo2 = $this->line($p, 'PHOTOGRAPHER', '500000', 'Фото — бонус');
        $mobile = $this->line($p, 'MOBILOGRAPHER', '700000', 'Мобилография');
        $video = $this->line($p, 'VIDEOGRAPHER', '2500000', 'Видеосъёмка');
        $shooter = $this->user('EXECUTOR', employee: ['specialties' => self::THREE]);
        $this->actingAsUser($this->rop)->post("/projects/{$p->id}/members", ['user_id' => $shooter->id, 'roles' => self::THREE])->assertSessionHasNoErrors();
        $m = $this->member($p, $shooter);
        // Одна строка фотографа уже начислена — её не трогаем
        $this->actingAsUser($this->ceo)->post("/finance/cost-lines/{$photo->id}/accrue")->assertSessionHasNoErrors();

        $this->actingAsUser($this->rop)->patch("/projects/{$p->id}/members/{$m->id}", ['roles' => ['', 'VIDEOGRAPHER']])->assertSessionHasNoErrors();
        $m->refresh();
        $this->assertSame(['VIDEOGRAPHER'], $m->positions());
        $this->assertSame('VIDEOGRAPHER', $m->role);
        $this->assertSame($shooter->id, $photo->fresh()->assignee_id);
        $this->assertSame('ACCRUED', $photo->fresh()->status);
        $this->assertNull($photo2->fresh()->assignee_id);
        $this->assertNull($mobile->fresh()->assignee_id);
        $this->assertSame($shooter->id, $video->fresh()->assignee_id);
        $audit = AuditLog::where('action', 'project.member_update')->where('entity_id', $p->id)->latest('created_at')->firstOrFail();
        $this->assertSame(['VIDEOGRAPHER', 'PHOTOGRAPHER', 'MOBILOGRAPHER'], $audit->changes['roles']['old']);
        $this->assertSame(['VIDEOGRAPHER'], $audit->changes['roles']['new']);

        // Освобождённая строка переходит к другому участнику с этой позицией
        $mobiler = $this->user('EXECUTOR', employee: ['specialty' => 'MOBILOGRAPHER']);
        $this->post("/projects/{$p->id}/members", ['user_id' => $mobiler->id])->assertSessionHasNoErrors();
        $this->assertSame($mobiler->id, $mobile->fresh()->assignee_id);

        // Позицию вернули — свободная строка снова его
        $this->patch("/projects/{$p->id}/members/{$m->id}", ['roles' => ['VIDEOGRAPHER', 'PHOTOGRAPHER']])->assertSessionHasNoErrors();
        $this->assertSame($shooter->id, $photo2->fresh()->assignee_id);
        $this->assertSame($mobiler->id, $mobile->fresh()->assignee_id);

        // Все позиции сняты — допустимо (как прежде роль могла быть пустой)
        $this->patch("/projects/{$p->id}/members/{$m->id}", ['roles' => ['']])->assertSessionHasNoErrors();
        $this->assertSame([], $m->fresh()->positions());
        $this->assertNull($m->fresh()->role);
        $this->assertNull($photo2->fresh()->assignee_id);
        $this->assertNull($video->fresh()->assignee_id);
    }

    public function test_template_tasks_go_to_member_by_any_position(): void
    {
        $p = $this->project(['start_date' => '2026-10-01']);
        $this->actingAsUser($this->ceo)->post('/settings/templates', ['name' => 'Съёмочный день', 'is_active' => '1', 'tasks' => [
            ['title' => 'Фото', 'role' => 'PHOTOGRAPHER', 'start_offset_days' => 0, 'duration_days' => 1, 'priority' => 'MEDIUM'],
            ['title' => 'Видео', 'role' => 'VIDEOGRAPHER', 'start_offset_days' => 0, 'duration_days' => 1, 'priority' => 'MEDIUM'],
            ['title' => 'Рилс на телефон', 'role' => 'MOBILOGRAPHER', 'start_offset_days' => 1, 'duration_days' => 1, 'priority' => 'MEDIUM'],
            ['title' => 'Тексты', 'role' => 'COPYWRITER', 'start_offset_days' => 1, 'duration_days' => 1, 'priority' => 'MEDIUM'],
        ]])->assertSessionHasNoErrors();
        $template = ProjectTemplate::where('name', 'Съёмочный день')->firstOrFail();
        $shooter = $this->user('EXECUTOR', employee: ['specialties' => self::THREE]);
        $this->actingAsUser($this->rop)->post("/projects/{$p->id}/members", ['user_id' => $shooter->id, 'roles' => self::THREE])->assertSessionHasNoErrors();

        // Шаблон применён после добавления — задачи по любой из позиций у него
        $this->post("/projects/{$p->id}/apply-template", ['template_id' => $template->id])->assertSessionHasNoErrors();
        foreach (['Фото', 'Видео', 'Рилс на телефон'] as $title) {
            $this->assertSame($shooter->id, Task::where('project_id', $p->id)->where('title', $title)->value('assignee_id'), $title);
        }
        $this->assertSame($this->rop->id, Task::where('project_id', $p->id)->where('title', 'Тексты')->value('assignee_id'));

        // Шаблон применён до добавления: задачи ждут у РОП и переходят, когда позицию добавили
        $p2 = $this->project(['start_date' => '2026-10-01']);
        $this->post("/projects/{$p2->id}/apply-template", ['template_id' => $template->id])->assertSessionHasNoErrors();
        $copywriter = $this->user('EXECUTOR', employee: ['specialty' => 'DESIGNER']);
        $this->post("/projects/{$p2->id}/members", ['user_id' => $copywriter->id, 'roles' => ['DESIGNER', 'MOBILOGRAPHER']])->assertSessionHasNoErrors();
        $this->assertSame($copywriter->id, Task::where('project_id', $p2->id)->where('title', 'Рилс на телефон')->value('assignee_id'));
        $this->assertSame($this->rop->id, Task::where('project_id', $p2->id)->where('title', 'Тексты')->value('assignee_id'));
        $this->patch("/projects/{$p2->id}/members/{$this->member($p2, $copywriter)->id}", ['roles' => ['DESIGNER', 'MOBILOGRAPHER', 'COPYWRITER']])->assertSessionHasNoErrors();
        $this->assertSame($copywriter->id, Task::where('project_id', $p2->id)->where('title', 'Тексты')->value('assignee_id'));
    }

    public function test_validation_rejects_unknown_position(): void
    {
        $p = $this->project();
        $this->actingAsUser($this->rop)->post("/projects/{$p->id}/members", ['user_id' => $this->executor->id, 'roles' => ['PHOTOGRAPHER', 'PILOT']])
            ->assertSessionHasErrors('roles.1');
        $this->assertFalse(ProjectMember::where('project_id', $p->id)->exists());
        $this->post("/projects/{$p->id}/members", ['user_id' => $this->executor->id, 'roles' => 'PHOTOGRAPHER'])->assertSessionHasErrors('roles');
        $this->post("/projects/{$p->id}/members", ['user_id' => $this->executor->id, 'roles' => ['SMM']])->assertSessionHasNoErrors();
        $m = $this->member($p, $this->executor);
        $this->patch("/projects/{$p->id}/members/{$m->id}", ['roles' => ['SMM', 'ASTRONAUT']])->assertSessionHasErrors('roles.1');
        $this->assertSame(['SMM'], $m->fresh()->positions());
        // Без права назначать — 403
        $this->actingAsUser($this->executor)->patch("/projects/{$p->id}/members/{$m->id}", ['roles' => ['SMM', 'DESIGNER']])->assertForbidden();
    }

    public function test_employee_specialties_are_default_positions_when_adding_member(): void
    {
        $this->actingAsUser($this->ceo)->post('/team/employees', [
            'email' => 'shooter@test.uz', 'full_name' => 'Фото Видео', 'role_code' => 'EXECUTOR', 'specialties' => ['', 'MOBILOGRAPHER', 'PHOTOGRAPHER', 'VIDEOGRAPHER'],
        ])->assertSessionHasNoErrors();
        $shooter = User::where('email', 'shooter@test.uz')->firstOrFail();
        $employee = Employee::where('user_id', $shooter->id)->firstOrFail();
        $this->assertSame('MOBILOGRAPHER', $employee->specialty);
        $this->assertSame(['MOBILOGRAPHER', 'VIDEOGRAPHER', 'PHOTOGRAPHER'], $employee->specialtyList());
        $this->post('/team/employees', ['email' => 'bad@test.uz', 'full_name' => 'Bad', 'role_code' => 'EXECUTOR', 'specialties' => ['PILOT']])
            ->assertSessionHasErrors('specialties.0');
        // Сотрудник с видео-специальностью среди нескольких — SMM-исполнитель
        $this->assertTrue(SmmAccess::isSmmExecutor($shooter->fresh()));
        $this->assertTrue(SmmAccess::isAssignable($shooter->id));

        // Правка карточки: специальности меняются, в аудите — старый и новый список
        $this->patch("/team/employees/{$shooter->id}", ['full_name' => 'Фото Видео', 'role_code' => 'EXECUTOR', 'specialties' => ['PHOTOGRAPHER', 'VIDEOGRAPHER']])->assertSessionHasNoErrors();
        $this->assertSame(['PHOTOGRAPHER', 'VIDEOGRAPHER'], $employee->fresh()->specialtyList());
        $audit = AuditLog::where('action', 'user.update')->where('entity_id', $shooter->id)->latest('created_at')->firstOrFail();
        $this->assertSame(['VIDEOGRAPHER', 'PHOTOGRAPHER', 'MOBILOGRAPHER'], $audit->changes['specialties']['old']);
        $this->assertSame(['VIDEOGRAPHER', 'PHOTOGRAPHER'], $audit->changes['specialties']['new']);
        $this->get('/team/employees')->assertOk()->assertSee(t('specialties.PHOTOGRAPHER').', '.t('specialties.VIDEOGRAPHER'));

        $p = $this->project();
        $this->actingAsUser($this->rop)->post("/projects/{$p->id}/members", ['user_id' => $shooter->id])->assertSessionHasNoErrors();
        $m = $this->member($p, $shooter);
        $this->assertSame(['PHOTOGRAPHER', 'VIDEOGRAPHER'], $m->positions());
        $this->assertSame('PHOTOGRAPHER', $m->role);
        // Форма добавления подставляет все специальности сотрудника
        $this->get("/projects/{$p->id}?tab=team")->assertOk()->assertSee(t('projects.team.positions'));
    }
}
