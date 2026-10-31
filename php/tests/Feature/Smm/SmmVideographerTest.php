<?php

namespace Tests\Feature\Smm;

use App\Models\AuditLog;
use App\Models\Direction;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\PayrollEntry;
use App\Models\SmmIdea;
use App\Models\SmmIdeaHistory;
use App\Models\User;
use App\Services\Smm\SmmAccess;
use App\Services\Smm\SmmIdeaService;
use App\Support\Navigation;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Видеограф идеи: выбор (права и специальность), обязателен для «Съёмка», засчитывается в план роликов, видит свои идеи. */
class SmmVideographerTest extends TestCase
{
    private User $ceo;

    private User $pm;

    private User $smm;

    private User $videographer;

    /** Мобилограф с ролью менеджера: раздел SMM ему иначе закрыт. */
    private User $mobilographer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ceo = $this->user('CEO');
        $this->pm = $this->user('PROJECT_MANAGER');
        DB::table('user_directions')->insert(['user_id' => $this->pm->id, 'direction_id' => Direction::where('code', 'MEDIA')->value('id')]);
        $this->smm = $this->user('EXECUTOR', employee: ['specialty' => 'SMM']);
        $this->videographer = $this->user('EXECUTOR', attrs: ['full_name' => 'Видеограф Бек'], employee: ['specialties' => ['VIDEOGRAPHER']]);
        $this->mobilographer = $this->user('MANAGER', attrs: ['full_name' => 'Мобилограф Дилшод'], employee: ['specialties' => ['MOBILOGRAPHER']]);
    }

    private function idea(string $status = 'APPROVED', array $attrs = []): SmmIdea
    {
        static $n = 0;
        $n++;

        return SmmIdea::create($attrs + [
            'title' => 'Ролик '.$n, 'status' => $status, 'author_id' => $this->smm->id, 'assignee_id' => $this->smm->id, 'sort_order' => $n,
            'pm_approved_by_id' => $this->pm->id, 'ceo_approved_by_id' => $this->ceo->id,
        ])->refresh();
    }

    public function test_assignment_rights_validation_history_audit_and_notification(): void
    {
        $idea = $this->idea('IDEA');
        $designer = $this->user('EXECUTOR', employee: ['specialty' => 'DESIGNER']);
        $blocked = $this->user('EXECUTOR', attrs: ['status' => 'BLOCKED'], employee: ['specialties' => ['VIDEOGRAPHER']]);

        // Карточка: список видеографов — только видеографы и мобилографы (любая роль)
        $this->actingAsUser($this->smm)->get("/smm/{$idea->id}")->assertOk()->assertSee(t('smm.fields.videographer'))
            ->assertSee('Видеограф Бек')->assertSee('Мобилограф Дилшод');
        $this->assertEqualsCanonicalizing([$this->videographer->id, $this->mobilographer->id], SmmAccess::videographers()->pluck('id')->all());

        // Не видеограф и заблокированный — нельзя
        $this->patch("/smm/{$idea->id}", ['videographer_id' => $designer->id])->assertSessionHasErrors('videographer_id');
        $this->patch("/smm/{$idea->id}", ['videographer_id' => $blocked->id])->assertSessionHasErrors('videographer_id');
        $this->assertNull($idea->fresh()->videographer_id);

        // Чужой SMM-специалист (не автор и не ответственный) — карточку не видит
        $other = $this->user('EXECUTOR', employee: ['specialty' => 'SMM']);
        $this->actingAsUser($other)->patch("/smm/{$idea->id}", ['videographer_id' => $this->videographer->id])->assertNotFound();

        // Ответственный выбирает видеографа: история, аудит, уведомление видеографу
        $this->actingAsUser($this->smm)->patch("/smm/{$idea->id}", ['videographer_id' => $this->videographer->id])->assertSessionHasNoErrors();
        $this->assertSame($this->videographer->id, $idea->fresh()->videographer_id);
        $h = SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'updated')->latest('created_at')->firstOrFail();
        $this->assertEquals(['old' => null, 'new' => 'Видеограф Бек'], $h->changes['videographer']);
        $this->assertTrue(AuditLog::where('action', 'smm.updated')->where('entity_id', $idea->id)->exists());
        Outbox::processBatch();
        $n = Notification::where('user_id', $this->videographer->id)->where('type', 'smm.videographer_assigned')->get();
        $this->assertCount(1, $n);
        $this->assertSame(t('smm.notify.videographerAssigned'), $n[0]->title);
        $this->assertSame('/smm/'.$idea->id, $n[0]->link);

        // Проект-менеджер меняет на мобилографа; CEO может снять (до съёмки — необязательно)
        $this->actingAsUser($this->pm)->patch("/smm/{$idea->id}", ['videographer_id' => $this->mobilographer->id])->assertSessionHasNoErrors();
        Outbox::processBatch();
        $this->assertSame(1, Notification::where('user_id', $this->mobilographer->id)->where('type', 'smm.videographer_assigned')->count());
        $this->actingAsUser($this->ceo)->patch("/smm/{$idea->id}", ['videographer_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull($idea->fresh()->videographer_id);

        // При создании идеи — тоже можно выбрать
        $this->actingAsUser($this->smm)->post('/smm', ['title' => 'С видеографом', 'videographer_id' => $this->videographer->id])->assertSessionHasNoErrors();
        $this->assertSame($this->videographer->id, SmmIdea::where('title', 'С видеографом')->value('videographer_id'));
        $this->post('/smm', ['title' => 'С дизайнером', 'videographer_id' => $designer->id])->assertSessionHasErrors('videographer_id');
    }

    public function test_videographer_required_when_moving_to_shooting(): void
    {
        $idea = $this->idea();
        $this->actingAsUser($this->smm)->postJson("/smm/{$idea->id}/move", ['status' => 'SHOOTING'])->assertStatus(422)
            ->assertJsonPath('errors.videographer_id.0', t('smm.errors.videographerRequired'));
        $this->postJson("/smm/{$idea->id}/move", ['status' => 'SHOOTING', 'videographer_id' => $this->smm->id])->assertStatus(422)
            ->assertJsonPath('errors.videographer_id.0', t('smm.errors.videographerInvalid'));
        $this->assertSame('APPROVED', $idea->fresh()->status);

        // Выбор при переносе: этап и видеограф — в одной записи истории
        $this->postJson("/smm/{$idea->id}/move", ['status' => 'SHOOTING', 'videographer_id' => $this->videographer->id])->assertOk();
        $idea->refresh();
        $this->assertSame(['SHOOTING', $this->videographer->id], [$idea->status, $idea->videographer_id]);
        $h = SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'status')->latest('created_at')->firstOrFail();
        $this->assertEquals(['old' => null, 'new' => 'Видеограф Бек'], $h->changes['videographer']);
        Outbox::processBatch();
        $this->assertTrue(Notification::where('user_id', $this->videographer->id)->where('type', 'smm.videographer_assigned')->exists());

        // Во время съёмки снять видеографа нельзя; заранее выбранный — переносится без вопроса
        $this->actingAsUser($this->smm)->patch("/smm/{$idea->id}", ['videographer_id' => ''])->assertSessionHasErrors('videographer_id');
        $this->assertSame($this->videographer->id, $idea->fresh()->videographer_id);
        $ready = $this->idea('APPROVED', ['videographer_id' => $this->mobilographer->id]);
        $this->postJson("/smm/{$ready->id}/move", ['status' => 'SHOOTING'])->assertOk();
    }

    public function test_videographer_sees_assigned_ideas_comments_and_moves_shooting_to_editing(): void
    {
        $mine = $this->idea('SHOOTING', ['videographer_id' => $this->mobilographer->id, 'title' => 'Мой ролик']);
        $notMine = $this->idea('SHOOTING', ['videographer_id' => $this->videographer->id, 'title' => 'Чужой ролик']);

        // Исполнитель-видеограф (OWN) видит идею, где он видеограф
        $this->actingAsUser($this->videographer)->get('/smm?view=list')->assertOk()->assertSee('Чужой ролик')->assertDontSee('Мой ролик');
        $this->get("/smm/{$notMine->id}")->assertOk();
        $this->get("/smm/{$mine->id}")->assertNotFound();

        // Менеджер-мобилограф без права smm.read: только назначенные ему идеи — просмотр, комментарий, «Съёмка → Монтаж»
        $hasMenu = fn (User $u) => collect(Navigation::for($this->as($u)))->contains(fn ($i) => ($i['key'] ?? null) === 'smm');
        $this->assertTrue($hasMenu($this->mobilographer));
        $this->actingAsUser($this->mobilographer)->get('/smm')->assertOk()->assertSee('Мой ролик')->assertDontSee('Чужой ролик');
        $this->get("/smm/{$mine->id}")->assertOk()->assertSee('Мой ролик');
        $this->get("/smm/{$notMine->id}")->assertNotFound();
        $this->post("/smm/{$mine->id}/comments", ['body' => 'Снято, отдаю в монтаж'])->assertSessionHasNoErrors();
        $this->patch("/smm/{$mine->id}", ['title' => 'x'])->assertForbidden();
        $this->post('/smm', ['title' => 'x'])->assertForbidden();
        $this->postJson("/smm/{$mine->id}/move", ['status' => 'SCHEDULED', 'planned_publish_date' => '2026-11-01'])->assertForbidden();
        $this->postJson("/smm/{$mine->id}/move", ['status' => 'EDITING'])->assertOk();
        $this->assertSame('EDITING', $mine->fresh()->status);
        $this->postJson("/smm/{$mine->id}/move", ['status' => 'SHOOTING'])->assertForbidden();

        // Без назначенных идей — раздел закрыт, меню нет
        $plainManager = $this->user('MANAGER', employee: ['specialties' => ['MOBILOGRAPHER']]);
        $this->actingAsUser($plainManager)->get('/smm')->assertForbidden();
        $this->assertFalse($hasMenu($plainManager));

        // Фильтр по видеографу в списке
        $this->actingAsUser($this->ceo)->get('/smm?view=list&videographer_id='.$this->mobilographer->id)->assertOk()
            ->assertSee('Мой ролик')->assertDontSee('Чужой ролик')->assertSee('Мобилограф Дилшод');
        $this->get('/smm')->assertOk()->assertSee('Видеограф Бек');
    }

    public function test_published_video_is_credited_to_videographer_and_assignee_in_payroll(): void
    {
        Employee::where('user_id', $this->videographer->id)->update(['base_salary' => 2000000, 'video_target' => 2]);
        Employee::where('user_id', $this->smm->id)->update(['base_salary' => 2000000, 'video_target' => 4]);
        $pub = fn (string $at, ?string $vg) => $this->idea('PUBLISHED', ['published_at' => $at, 'videographer_id' => $vg]);
        $pub('2026-09-10 08:00:00', $this->videographer->id);
        $pub('2026-09-11 08:00:00', null);
        $pub('2026-09-30 21:00:00', $this->videographer->id); // 1 октября по Ташкенту — не сентябрь
        $this->idea('EDITING', ['videographer_id' => $this->videographer->id]); // не опубликован
        // Видеограф сам себе ответственный — ролик считается один раз
        $this->idea('PUBLISHED', ['published_at' => '2026-09-12 08:00:00', 'assignee_id' => $this->videographer->id, 'author_id' => $this->videographer->id, 'videographer_id' => $this->videographer->id]);

        $this->assertSame(2, SmmIdeaService::publishedCount($this->videographer->id, '2026-09'));
        $this->assertSame(2, SmmIdeaService::publishedCount($this->smm->id, '2026-09'));
        $this->assertSame(['assignee' => 1, 'videographer' => 2], SmmIdeaService::publishedBreakdown([$this->videographer->id], '2026-09')[$this->videographer->id]);

        $this->actingAsUser($this->ceo)->post('/finance/payroll/calculate', ['period' => '2026-09'])->assertRedirect();
        $vg = PayrollEntry::where('user_id', $this->videographer->id)->where('period', '2026-09')->firstOrFail();
        $this->assertSame([2, 2, '2000000.00'], [$vg->videos_published, $vg->videos_target, (string) $vg->base_salary]);
        $smm = PayrollEntry::where('user_id', $this->smm->id)->where('period', '2026-09')->firstOrFail();
        $this->assertSame([2, 4, '1000000.00'], [$smm->videos_published, $smm->videos_target, (string) $smm->base_salary]);

        // Блок «SMM: оклад и план роликов» — счётчик по ролям
        $this->get('/finance/payroll?period=2026-09')->assertOk()
            ->assertSee(t('payroll.smmCounts', ['period' => '2026-09', 'assignee' => 1, 'videographer' => 2]))
            ->assertSee(t('payroll.smmCounts', ['period' => '2026-09', 'assignee' => 2, 'videographer' => 0]));
    }
}
