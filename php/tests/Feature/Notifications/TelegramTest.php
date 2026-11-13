<?php

namespace Tests\Feature\Notifications;

use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\Setting;
use App\Services\Telegram\TelegramSender;
use App\Services\Telegram\TelegramService;
use App\Support\Lang;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramTest extends TestCase
{
    use CommsFixtures;

    /** @var list<array{method:string,body:array}> */
    private array $calls = [];

    private ?array $failNext = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures();
        config(['fluggi.telegram.token' => '123:test', 'fluggi.telegram.webhook_secret' => 'webhook-secret-123', 'app.url' => 'https://crm.test']);
        Http::fake(function (Request $r) {
            $method = basename(parse_url($r->url(), PHP_URL_PATH));
            $this->calls[] = ['method' => $method, 'body' => $r->data()];
            if ($this->failNext && $method === ($this->failNext['method'] ?? 'sendMessage')) {
                $f = $this->failNext;
                $this->failNext = null;

                return Http::response(['ok' => false, 'description' => $f['description'], 'parameters' => isset($f['retry_after']) ? ['retry_after' => $f['retry_after']] : null], $f['status']);
            }
            $result = match ($method) {
                'getMe' => ['id' => 1, 'username' => 'fluggi_test_bot'],
                'getWebhookInfo' => ['url' => 'https://crm.test/hooks/telegram', 'pending_update_count' => 0],
                default => ['message_id' => 1],
            };

            return Http::response(['ok' => true, 'result' => $result]);
        });
        // Cron проверил бота
        TelegramService::check();
        $this->calls = [];
    }

    private function webhook(string $text, int $chatId = 777, string $secret = 'webhook-secret-123')
    {
        return $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $secret)->postJson('/hooks/telegram', [
            'update_id' => 1,
            'message' => ['message_id' => 1, 'text' => $text, 'chat' => ['id' => $chatId, 'type' => 'private'], 'from' => ['id' => $chatId, 'username' => 'aziz']],
        ]);
    }

    private function link($user, int $chatId = 777): array
    {
        $l = $this->actingAsUser($user)->postJson('/me/telegram/link')->assertOk()->json();
        $this->webhook($l['command'], $chatId)->assertOk();

        return $l;
    }

    public function test_link_by_one_time_code_unlink_and_webhook_secret(): void
    {
        $this->actingAsUser($this->manager)->getJson('/me/telegram')->assertExactJson([
            'botConfigured' => true, 'botUsername' => 'fluggi_test_bot', 'linked' => false, 'username' => null, 'problem' => null,
        ]);
        $l = $this->link($this->manager);
        $this->assertMatchesRegularExpression('#^https://t\.me/fluggi_test_bot\?start=[\w-]+$#', $l['deepLink']);
        $this->actingAsUser($this->manager)->getJson('/me/telegram')->assertJson(['linked' => true, 'username' => 'aziz']);
        $this->assertSame('777', collect($this->calls)->firstWhere('method', 'sendMessage')['body']['chat_id']);

        // Код одноразовый
        $this->webhook($l['command'], 778)->assertOk();
        $this->assertSame('777', $this->manager->fresh()->telegram_chat_id);
        $this->assertStringContainsString('недействителен', end($this->calls)['body']['text']);
        // Один чат — один аккаунт
        $this->link($this->rop, 777);
        $this->assertNull($this->manager->fresh()->telegram_chat_id);

        // Без секрета или с чужим — 403
        $this->webhook('/stop', 777, 'wrong')->assertForbidden();
        $this->postJson('/hooks/telegram', [])->assertForbidden();
        $this->assertSame('777', $this->rop->fresh()->telegram_chat_id);

        $this->webhook('/stop')->assertOk();
        $this->assertNull($this->rop->fresh()->telegram_chat_id);
        $this->link($this->rop);
        $this->actingAsUser($this->rop)->deleteJson('/me/telegram')->assertNoContent();
        $this->assertNull($this->rop->fresh()->telegram_chat_id);
    }

    public function test_problems_are_shown_and_link_refused(): void
    {
        $problem = fn () => $this->actingAsUser($this->manager)->getJson('/me/telegram')->json('problem');
        $this->failNext = ['status' => 401, 'description' => 'Unauthorized', 'method' => 'getMe'];
        $this->assertFalse(TelegramService::check());
        $this->assertStringContainsString('TELEGRAM_BOT_TOKEN', $problem());
        $this->actingAsUser($this->manager)->postJson('/me/telegram/link')->assertStatus(422);

        $this->assertTrue(TelegramService::check());
        $this->assertNull($problem());

        config(['fluggi.telegram.username' => '@other_bot']);
        $this->assertFalse(TelegramService::check());
        $this->assertStringContainsString('other_bot', $problem());
        config(['fluggi.telegram.username' => null]);

        // Webhook указывает не туда
        config(['app.url' => 'https://other.test']);
        $this->assertFalse(TelegramService::check());
        $this->assertStringContainsString('fluggi:telegram-webhook', $problem());
        config(['app.url' => 'https://crm.test']);
        $this->assertTrue(TelegramService::check());

        // Cron давно не проверял бота — фоновые задачи не работают
        Setting::whereKey(TelegramService::HEALTH_KEY)->update(['value' => json_encode(['at' => now()->subMinutes(30)->toIso8601ZuluString(), 'problem' => null, 'bot' => 'fluggi_test_bot'])]);
        $this->assertStringContainsString('schedule:run', $problem());
        $this->actingAsUser($this->manager)->postJson('/me/telegram/link')->assertStatus(422);

        // Бот не настроен
        config(['fluggi.telegram.token' => null]);
        $this->actingAsUser($this->manager)->getJson('/me/telegram')->assertJson(['botConfigured' => false, 'problem' => null]);
        $this->actingAsUser($this->manager)->get('/profile')->assertOk()->assertSee(t('telegram.notConfigured'));
    }

    public function test_delivery_queue_retry_backoff_and_permanent_failure(): void
    {
        $this->actingAsUser($this->manager)->postJson('/me/telegram/test')->assertStatus(422);
        $this->link($this->manager);
        $this->calls = [];
        $this->actingAsUser($this->manager)->postJson('/me/telegram/test')->assertOk();
        $this->assertSame(0, Notification::where('user_id', $this->manager->id)->count());

        $this->failNext = ['status' => 429, 'description' => 'Too Many Requests', 'retry_after' => 1];
        $this->assertSame(1, TelegramSender::processBatch());
        $d = NotificationDelivery::firstOrFail();
        $this->assertSame(['PENDING', 1], [$d->status, $d->attempts]);
        $this->assertStringContainsString('Too Many Requests', $d->last_error);
        // Повтор — после паузы
        $this->assertSame(0, TelegramSender::processBatch());
        $d->update(['next_attempt_at' => now()->subSecond()]);
        $this->assertSame(1, TelegramSender::processBatch());
        $d->refresh();
        $this->assertSame(['SENT', 2], [$d->status, $d->attempts]);
        $sent = collect($this->calls)->where('method', 'sendMessage')->last()['body'];
        $this->assertSame(['777', 'HTML'], [$sent['chat_id'], $sent['parse_mode']]);
        $this->assertStringContainsString('<b>Проверка связи</b>', $sent['text']);
        $this->assertStringContainsString('https://crm.test/profile', $sent['text']);

        // Бот заблокирован (403) — без повторов
        $this->actingAsUser($this->manager)->postJson('/me/telegram/test')->assertOk();
        $this->failNext = ['status' => 403, 'description' => 'Forbidden: bot was blocked by the user'];
        TelegramSender::processBatch();
        $this->assertSame('FAILED', NotificationDelivery::orderByDesc('created_at')->first()->status);
        $this->assertSame(60, TelegramSender::backoff(2) / 2 * 1);
        $this->assertSame(1800, TelegramSender::backoff(10));
    }

    public function test_profile_card_renders_in_both_languages(): void
    {
        foreach (['ru', 'uz'] as $loc) {
            $this->manager->update(['locale' => $loc]);
            $this->actingAsUser($this->manager)->get('/profile')->assertOk()->assertSee(Lang::get('telegram.connect', [], $loc));
        }
        $this->link($this->manager);
        $this->actingAsUser($this->manager)->get('/profile')->assertOk()->assertSee('@aziz')->assertSee(t('telegram.unlink'));
        $this->actingAsUser($this->manager)->get('/notifications')->assertOk()->assertSee('@aziz');
    }
}
