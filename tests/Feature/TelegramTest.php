<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Student;
use App\Models\TelegramAccount;
use App\Services\NotificationService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:SECRET', 'services.telegram.webhook_secret' => 'hook-secret', 'services.telegram.bot_username' => 'edu_bot']);
    }

    private function queue(string $body = 'hello'): Notification
    {
        return app(NotificationService::class)->queueTelegram('system', $body, 4242);
    }

    public function test_sends_queued_messages(): void
    {
        $this->makeOrg();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $n = $this->queue();

        $this->assertSame([1, 0], app(TelegramService::class)->processQueue());
        $this->assertSame('sent', $n->fresh()->status);
        $this->assertNotNull($n->fresh()->sent_at);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'bot123:SECRET/sendMessage') && $r['chat_id'] == 4242 && $r['text'] === 'hello');
    }

    public function test_failure_is_retried_then_marked_failed_and_admins_are_alerted(): void
    {
        $this->makeOrg();
        $admin = $this->makeUser('super_admin');
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)->push(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)
            ->push(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)->push(['ok' => true])]);
        $n = $this->queue();
        $svc = app(TelegramService::class);

        $svc->processQueue();
        $n->refresh();
        $this->assertSame(['pending', 1], [$n->status, $n->attempts]);       // retry scheduled
        $this->assertStringContainsString('blocked', $n->error);
        $this->assertTrue($n->scheduled_at->isFuture());
        $this->assertSame([0, 0], $svc->processQueue());                    // back-off: not yet due

        foreach ([2, 3] as $attempt) {
            $n->refresh()->update(["scheduled_at" => now()->subMinutes(30)]);
            $svc->processQueue();
        }
        $n->refresh();
        $this->assertSame(['failed', 3], [$n->status, $n->attempts]);
        $this->assertTrue(Notification::where('channel', 'web')->where('user_id', $admin->id)->where('title', 'Ошибка Telegram')->exists());   // shows in the bell

        // manual retry puts it back and succeeds
        $svc->retry($n);
        $svc->processQueue();
        $this->assertSame('sent', $n->fresh()->status);
    }

    public function test_bot_token_never_leaks_into_stored_errors(): void
    {
        $this->makeOrg();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error: could not connect to https://api.telegram.org/bot123:SECRET/sendMessage'));
        $n = $this->queue();
        app(TelegramService::class)->processQueue();
        $this->assertStringNotContainsString('SECRET', $n->fresh()->error);
    }

    public function test_missing_token_is_reported_not_crashing(): void
    {
        $this->makeOrg();
        config(['services.telegram.bot_token' => null]);
        $n = $this->queue();
        app(TelegramService::class)->processQueue();
        $this->assertStringContainsString('TOKEN', $n->fresh()->error);
    }

    public function test_linking_by_numeric_telegram_id_via_start_token(): void
    {
        $this->makeOrg();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $student = $this->makeStudent();
        $acc = TelegramAccount::issueFor($student);
        $this->assertNotEmpty($acc->token);

        $update = ['message' => ['text' => '/start '.$acc->token, 'chat' => ['id' => 9001], 'from' => ['id' => 9001, 'username' => 'renamed_later']]];
        $this->postJson('/telegram/webhook/wrong', $update)->assertForbidden();
        $this->postJson('/telegram/webhook/hook-secret', $update)->assertOk();
        \App\Support\Tenant::set($acc->organization_id);      // the request cleared the tenant context

        $acc->refresh();
        $this->assertTrue($acc->isLinked());
        $this->assertSame(9001, (int) $acc->telegram_user_id);
        $this->assertNull($acc->token);                                   // single-use
        $this->assertSame($student->id, $acc->linkable->id);

        // the same Telegram user cannot be attached to a second entity: the newest link wins
        $other = $this->makeStudent(['first_name' => 'Other']);
        $acc2 = TelegramAccount::issueFor($other);
        $this->postJson('/telegram/webhook/hook-secret', ['message' => ['text' => '/start '.$acc2->token, 'chat' => ['id' => 9001], 'from' => ['id' => 9001]]])->assertOk();
        $this->assertSame(1, TelegramAccount::whereNotNull('telegram_user_id')->count());

        // a wrong token does not link
        $this->postJson('/telegram/webhook/hook-secret', ['message' => ['text' => '/start aaaaaaaaaaaaaaaa', 'chat' => ['id' => 5], 'from' => ['id' => 5]]])->assertOk();
        $this->assertSame(1, TelegramAccount::whereNotNull('telegram_user_id')->count());
    }

    public function test_templates_are_customisable_and_new_lead_alert_matches_spec_format(): void
    {
        $this->makeOrg();
        $svc = app(NotificationService::class);
        $text = $svc->render('new_lead', ['name' => 'Muhammad', 'phone' => '+998901234567', 'course' => 'IELTS', 'branch' => 'Nurtepa', 'source' => 'Instagram']);
        $this->assertStringContainsString("🔔 Новая заявка\n\n👤 Muhammad\n📞 +998901234567\n📚 IELTS\n📍 Nurtepa\n📱 Instagram", $text);

        \App\Models\Setting::put('tpl.payment_due', 'Долг: {amount}');
        \App\Models\Setting::flush();
        $this->assertSame('Долг: 200 000', $svc->render('payment_due', ['amount' => '200 000']));
    }

    public function test_daily_digest_command_notifies_directors(): void
    {
        $this->makeOrg();
        $director = $this->makeUser('director');
        $this->artisan('report:daily')->assertSuccessful();
        $n = Notification::where('user_id', $director->id)->first();
        $this->assertStringContainsString('ОТЧЁТ ЗА '.now()->format('d.m.Y'), $n->body);
        $this->artisan('report:daily')->assertSuccessful();               // same day twice → still one
        $this->assertSame(1, Notification::where('user_id', $director->id)->count());
    }

    public function test_cron_commands_run_cleanly(): void
    {
        $this->makeOrg();
        foreach (['notifications:process', 'reminders:lessons', 'debts:check', 'lessons:extend', 'salary:accrue'] as $cmd) {
            $this->artisan($cmd)->assertSuccessful();
        }
    }
}
