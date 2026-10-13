<?php

namespace Tests\Feature\Smm;

use App\Models\AuditLog;
use App\Models\Direction;
use App\Models\Notification;
use App\Models\OutboxEvent;
use App\Models\SmmIdea;
use App\Models\SmmIdeaHistory;
use App\Models\User;
use App\Support\Navigation;
use App\Support\Outbox;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Раздел «SMM»: права, два утверждения (проект-менеджер + CEO), отклонение, этапы, история, уведомления. */
class SmmIdeasTest extends TestCase
{
    private User $ceo;

    private User $pm;

    private User $pmOther;

    private User $smm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ceo = $this->user('CEO');
        // Проект-менеджер направления «Медиа» отвечает за SMM; второй — без направления
        $this->pm = $this->user('PROJECT_MANAGER');
        DB::table('user_directions')->insert(['user_id' => $this->pm->id, 'direction_id' => Direction::where('code', 'MEDIA')->value('id')]);
        $this->pmOther = $this->user('PROJECT_MANAGER');
        $this->smm = $this->user('EXECUTOR', employee: ['specialty' => 'SMM']);
    }

    private function createIdea(?User $as = null, array $data = []): SmmIdea
    {
        $this->actingAsUser($as ?? $this->smm)->post('/smm', $data + ['title' => 'Закулисье съёмки', 'scenario' => "Кадр 1: офис\nКадр 2: команда"])
            ->assertSessionHasNoErrors()->assertRedirect();

        return SmmIdea::latest('number')->firstOrFail();
    }

    private function approved(): SmmIdea
    {
        $idea = $this->createIdea();
        $this->actingAsUser($this->pm)->post("/smm/{$idea->id}/approve")->assertSessionHasNoErrors();
        $this->actingAsUser($this->ceo)->post("/smm/{$idea->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', $idea->fresh()->status);

        return $idea->fresh();
    }

    public function test_smm_executor_creates_idea_and_approvers_are_notified(): void
    {
        $idea = $this->createIdea();
        $this->assertSame('IDEA', $idea->status);
        $this->assertSame($this->smm->id, $idea->assignee_id, 'SMM-специалист по умолчанию — ответственный');
        $this->assertSame($this->smm->id, $idea->author_id);
        $this->assertSame('V-'.str_pad((string) $idea->number, 5, '0', STR_PAD_LEFT), $idea->code());
        $this->assertTrue(SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'created')->exists());
        $this->assertTrue(AuditLog::where('action', 'smm.created')->where('entity_id', $idea->id)->exists());
        $this->assertTrue(OutboxEvent::where('type', 'smm.idea_created')->exists());

        Outbox::processBatch();
        $notified = Notification::where('type', 'smm.idea_created')->pluck('user_id')->all();
        sort($notified);
        $expected = [$this->ceo->id, $this->pm->id];
        sort($expected);
        $this->assertSame($expected, $notified, 'проект-менеджер направления «Медиа» и CEO; второй ПМ и автор — нет');
        $this->assertSame('/smm/'.$idea->id, Notification::where('user_id', $this->pm->id)->value('link'));
    }

    public function test_all_project_managers_are_notified_when_none_has_media_direction(): void
    {
        DB::table('user_directions')->where('user_id', $this->pm->id)->delete();
        $this->createIdea();
        Outbox::processBatch();
        $this->assertEqualsCanonicalizing([$this->ceo->id, $this->pm->id, $this->pmOther->id], Notification::where('type', 'smm.idea_created')->pluck('user_id')->all());
    }

    public function test_access_by_role_and_specialty(): void
    {
        $designer = $this->user('EXECUTOR', employee: ['specialty' => 'DESIGNER']);
        $plain = $this->user('EXECUTOR');
        $manager = $this->user('MANAGER');
        $editor = $this->user('EXECUTOR', employee: ['specialty' => 'EDITOR']);

        $this->actingAsUser($designer)->get('/smm')->assertForbidden();
        $this->actingAsUser($designer)->post('/smm', ['title' => 'x'])->assertForbidden();
        $this->actingAsUser($plain)->get('/smm')->assertForbidden();
        $this->actingAsUser($manager)->get('/smm')->assertForbidden();
        $this->actingAsUser($this->smm)->get('/smm')->assertOk();
        $this->actingAsUser($this->pm)->get('/smm')->assertOk();
        $this->actingAsUser($this->ceo)->get('/smm')->assertOk();

        // Меню: SMM-специалисту и ПМ — есть, исполнителю без SMM-специальности — нет
        $hasMenu = fn (User $u) => collect(Navigation::for($this->as($u)))->contains(fn ($i) => ($i['key'] ?? null) === 'smm');
        $this->assertTrue($hasMenu($this->smm));
        $this->assertTrue($hasMenu($this->pm));
        $this->assertFalse($hasMenu($designer));
        $this->assertFalse($hasMenu($manager));

        // OWN: чужую идею исполнитель не видит
        $idea = $this->createIdea();
        $this->actingAsUser($editor)->get("/smm/{$idea->id}")->assertNotFound();
        $this->actingAsUser($editor)->get('/smm?view=list')->assertOk()->assertDontSee($idea->title);
        $this->actingAsUser($this->smm)->get("/smm/{$idea->id}")->assertOk()->assertSee($idea->title);
        $this->actingAsUser($this->pm)->get('/smm?view=list')->assertOk()->assertSee($idea->title);

        // Утверждать исполнитель не может; назначить ответственным — только SMM-специалиста
        $this->actingAsUser($this->smm)->post("/smm/{$idea->id}/approve")->assertForbidden();
        $this->actingAsUser($this->pm)->post('/smm', ['title' => 'Без ответственного'])->assertSessionHasErrors('assignee_id');
        $this->actingAsUser($this->pm)->post('/smm', ['title' => 'Дизайнеру', 'assignee_id' => $designer->id])->assertSessionHasErrors('assignee_id');
        $this->actingAsUser($this->pm)->post('/smm', ['title' => 'Монтажёру', 'assignee_id' => $editor->id])->assertSessionHasNoErrors();
        $this->actingAsUser($editor)->get('/smm?view=list')->assertOk()->assertSee('Монтажёру');
    }

    public function test_both_project_manager_and_ceo_must_approve(): void
    {
        $idea = $this->createIdea();
        // До утверждения снимать нельзя
        $this->actingAsUser($this->smm)->postJson("/smm/{$idea->id}/move", ['status' => 'SHOOTING'])->assertStatus(422);
        $this->actingAsUser($this->smm)->postJson("/smm/{$idea->id}/move", ['status' => 'APPROVED'])->assertStatus(422);
        // Отклонить можно только кнопкой с причиной, не перетаскиванием
        $this->actingAsUser($this->ceo)->postJson("/smm/{$idea->id}/move", ['status' => 'REJECTED'])->assertStatus(422);

        $this->actingAsUser($this->ceo)->post("/smm/{$idea->id}/approve")->assertSessionHasNoErrors();
        $idea->refresh();
        $this->assertSame('IDEA', $idea->status, 'одной подписи CEO мало, пока есть проект-менеджеры');
        $this->assertSame($this->ceo->id, $idea->ceo_approved_by_id);
        $this->assertNotNull($idea->ceo_approved_at);
        $this->actingAsUser($this->ceo)->post("/smm/{$idea->id}/approve")->assertSessionHas('error');
        $this->assertFalse(OutboxEvent::where('type', 'smm.idea_approved')->exists());

        $this->actingAsUser($this->pmOther)->post("/smm/{$idea->id}/approve")->assertSessionHasNoErrors();
        $idea->refresh();
        $this->assertSame('APPROVED', $idea->status);
        $this->assertSame($this->pmOther->id, $idea->pm_approved_by_id);
        $this->assertTrue(OutboxEvent::where('type', 'smm.idea_approved')->exists());
        $this->assertEqualsCanonicalizing(['created', 'approved_ceo', 'approved_pm', 'status'], SmmIdeaHistory::where('idea_id', $idea->id)->pluck('action')->all());

        Outbox::processBatch();
        $this->assertTrue(Notification::where('type', 'smm.idea_approved')->where('user_id', $this->smm->id)->exists());
        $this->actingAsUser($this->smm)->postJson("/smm/{$idea->id}/move", ['status' => 'SHOOTING'])->assertOk();
    }

    public function test_ceo_approval_is_enough_without_project_managers(): void
    {
        User::whereIn('id', [$this->pm->id, $this->pmOther->id])->update(['status' => 'BLOCKED']);
        $idea = $this->createIdea();
        $this->actingAsUser($this->ceo)->post("/smm/{$idea->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('APPROVED', $idea->fresh()->status);
        $this->actingAsUser($this->ceo)->get("/smm/{$idea->id}")->assertOk()->assertSee(t('smm.pmNotRequired'));
    }

    public function test_rejection_with_reason_and_resubmission(): void
    {
        $idea = $this->createIdea();
        $this->actingAsUser($this->pm)->post("/smm/{$idea->id}/approve");
        $this->actingAsUser($this->ceo)->post("/smm/{$idea->id}/reject", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAsUser($this->ceo)->post("/smm/{$idea->id}/reject", ['reason' => 'Не наш формат'])->assertSessionHasNoErrors();
        $idea->refresh();
        $this->assertSame('REJECTED', $idea->status);
        $this->assertSame('Не наш формат', $idea->reject_reason);
        $this->assertSame($this->ceo->id, $idea->rejected_by_id);
        $this->assertSame('Не наш формат', SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'rejected')->value('comment'));
        $this->assertTrue(AuditLog::where('action', 'smm.rejected')->where('entity_id', $idea->id)->exists());

        Outbox::processBatch();
        $this->assertTrue(Notification::where('type', 'smm.idea_rejected')->where('user_id', $this->smm->id)->exists());

        // Отклонённую — нельзя в работу
        $this->actingAsUser($this->smm)->postJson("/smm/{$idea->id}/move", ['status' => 'SHOOTING'])->assertStatus(422);

        // Повторная отправка: подписи сбрасываются, утверждающие снова получают уведомление
        Notification::query()->delete();
        $this->actingAsUser($this->smm)->post("/smm/{$idea->id}/move", ['status' => 'IDEA'])->assertSessionHasNoErrors();
        $idea->refresh();
        $this->assertSame('IDEA', $idea->status);
        $this->assertNull($idea->pm_approved_by_id);
        $this->assertNull($idea->reject_reason);
        $this->assertTrue(SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'resubmitted')->exists());
        Outbox::processBatch();
        $this->assertEqualsCanonicalizing([$this->ceo->id, $this->pm->id], Notification::where('type', 'smm.idea_created')->pluck('user_id')->all());
    }

    public function test_production_stages_and_published_revert_by_ceo_only(): void
    {
        $idea = $this->approved();
        $this->actingAsUser($this->smm);
        $this->postJson("/smm/{$idea->id}/move", ['status' => 'SHOOTING'])->assertOk();
        $this->postJson("/smm/{$idea->id}/move", ['status' => 'EDITING'])->assertOk();
        $this->postJson("/smm/{$idea->id}/move", ['status' => 'SHOOTING'])->assertOk(); // назад — можно
        $this->postJson("/smm/{$idea->id}/move", ['status' => 'SCHEDULED'])->assertStatus(422)->assertJsonPath('errors.planned_publish_date.0', t('smm.errors.plannedDateRequired'));
        $this->postJson("/smm/{$idea->id}/move", ['status' => 'SCHEDULED', 'planned_publish_date' => '2026-10-20'])->assertOk();
        $this->assertSame('2026-10-20', $idea->fresh()->planned_publish_date->format('Y-m-d'));
        $this->postJson("/smm/{$idea->id}/move", ['status' => 'IDEA'])->assertStatus(422);
        $this->postJson("/smm/{$idea->id}/move", ['status' => 'PUBLISHED', 'published_at' => '2026-10-20T19:30', 'publish_url' => 'https://instagram.com/p/abc'])->assertOk();
        $idea->refresh();
        $this->assertSame('PUBLISHED', $idea->status);
        $this->assertSame('2026-10-20 14:30:00', $idea->published_at->format('Y-m-d H:i:s'), 'время по Ташкенту хранится в UTC');
        $this->assertSame('https://instagram.com/p/abc', $idea->publish_url);

        // Опубликованный возвращает только CEO
        $this->postJson("/smm/{$idea->id}/move", ['status' => 'EDITING'])->assertStatus(422);
        $this->actingAsUser($this->pm)->postJson("/smm/{$idea->id}/move", ['status' => 'EDITING'])->assertStatus(422);
        $this->actingAsUser($this->ceo)->postJson("/smm/{$idea->id}/move", ['status' => 'EDITING'])->assertOk();
        $this->assertNull($idea->fresh()->published_at);

        // Другой SMM-специалист (не ответственный) двигать не может
        $other = $this->user('EXECUTOR', employee: ['specialty' => 'VIDEOGRAPHER']);
        $this->actingAsUser($other)->postJson("/smm/{$idea->id}/move", ['status' => 'SHOOTING'])->assertNotFound();

        $statuses = SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'status')->orderBy('created_at')->get()
            ->map(fn ($h) => $h->changes['status']['old'].'→'.$h->changes['status']['new'])->all();
        $this->assertSame(['IDEA→APPROVED', 'APPROVED→SHOOTING', 'SHOOTING→EDITING', 'EDITING→SHOOTING', 'SHOOTING→SCHEDULED', 'SCHEDULED→PUBLISHED', 'PUBLISHED→EDITING'], $statuses);
        $this->assertSame(7, AuditLog::where('action', 'smm.status')->where('entity_id', $idea->id)->count());
        $this->assertTrue(OutboxEvent::where('type', 'smm.idea_status_changed')->exists());
        Outbox::processBatch();
        $this->assertTrue(Notification::where('type', 'smm.idea_status_changed')->where('user_id', $this->smm->id)->exists(), 'CEO вернул ролик — ответственный узнаёт');
    }

    public function test_edits_and_comments_are_in_history(): void
    {
        $idea = $this->createIdea();
        $editor = $this->user('EXECUTOR', employee: ['specialty' => 'EDITOR']);
        $this->actingAsUser($this->smm)->patch("/smm/{$idea->id}", [
            'title' => 'Закулисье съёмки v2', 'scenario' => 'Новый сценарий', 'assignee_id' => $editor->id, 'planned_publish_date' => '2026-11-01',
        ])->assertSessionHasNoErrors();
        $h = SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'updated')->firstOrFail();
        $this->assertEquals(['old' => 'Закулисье съёмки', 'new' => 'Закулисье съёмки v2'], $h->changes['title']);
        $this->assertSame("Кадр 1: офис\nКадр 2: команда", $h->changes['scenario']['old']);
        $this->assertSame('Новый сценарий', $h->changes['scenario']['new']);
        $this->assertEquals(['old' => $this->smm->full_name, 'new' => $editor->full_name], $h->changes['assignee']);
        $this->assertEquals(['old' => null, 'new' => '2026-11-01'], $h->changes['planned_publish_date']);
        $this->assertSame($this->smm->id, $h->user_id);
        $this->assertTrue(AuditLog::where('action', 'smm.updated')->where('entity_id', $idea->id)->exists());

        // Чужой SMM-специалист не редактирует
        $other = $this->user('EXECUTOR', employee: ['specialty' => 'SMM']);
        $this->actingAsUser($other)->patch("/smm/{$idea->id}", ['title' => 'x'])->assertNotFound();

        $this->actingAsUser($this->pm)->post("/smm/{$idea->id}/comments", ['body' => 'Добавьте субтитры'])->assertSessionHasNoErrors();
        $this->assertSame('Добавьте субтитры', SmmIdeaHistory::where('idea_id', $idea->id)->where('action', 'commented')->value('comment'));
        $this->actingAsUser($this->smm)->get("/smm/{$idea->id}")->assertOk()
            ->assertSee('Добавьте субтитры')->assertSee(t('smm.history.updated'))->assertSee('Закулисье съёмки v2');
    }

    public function test_pages_render_in_all_languages(): void
    {
        $idea = $this->approved();
        $this->actingAsUser($this->smm)->postJson("/smm/{$idea->id}/move", ['status' => 'SHOOTING'])->assertOk();
        $rejected = $this->createIdea(data: ['title' => 'Отклонённая идея']);
        $this->actingAsUser($this->ceo)->post("/smm/{$rejected->id}/reject", ['reason' => 'Дорого']);
        foreach (['uz', 'ru', 'en'] as $locale) {
            foreach ([$this->ceo, $this->pm, $this->smm] as $u) {
                $u->update(['locale' => $locale]);
                $this->actingAsUser($u);
                app()->setLocale($locale);
                $this->get('/smm')->assertOk()->assertSee(t('smm.status.SHOOTING'))->assertSee($idea->title);
                $this->get('/smm?view=list&status=SHOOTING&month='.now()->format('Y-m'))->assertOk()->assertSee($idea->title);
                $this->get("/smm/{$idea->id}")->assertOk()->assertSee(t('smm.historyTitle'));
                $this->get("/smm/{$rejected->id}")->assertOk()->assertSee('Дорого');
            }
        }
    }

    public function test_migration_grants_new_permissions_to_existing_roles(): void
    {
        $perm = fn (string $role, string $code) => DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('r.code', $role)->where('p.code', $code)->value('rp.scope');
        $this->assertSame('ALL', $perm('PROJECT_MANAGER', 'smm.approve'));
        $this->assertSame('OWN', $perm('EXECUTOR', 'smm.read'));
        $this->assertNull($perm('EXECUTOR', 'smm.approve'));
        $this->assertNull($perm('MANAGER', 'smm.read'));

        // Установка до модуля SMM: у ролей уже есть права, новых — нет
        DB::table('role_permissions')->whereIn('permission_id', DB::table('permissions')->where('code', 'like', 'smm.%')->pluck('id'))->delete();
        $migration = require database_path('migrations/2026_10_14_000000_smm.php');
        (new \ReflectionMethod($migration, 'grantPermissions'))->invoke($migration);
        $this->assertSame('ALL', $perm('PROJECT_MANAGER', 'smm.approve'));
        $this->assertSame('ALL', $perm('CEO', 'smm.read'));
        $this->assertSame('OWN', $perm('EXECUTOR', 'smm.create'));
        $this->assertNull($perm('ROP', 'smm.read'));
    }
}
