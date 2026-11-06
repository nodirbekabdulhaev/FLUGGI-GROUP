<?php

namespace Tests\Feature\Notifications;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationSetting;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\Notifier;
use App\Services\Push\PushSender;
use App\Services\Push\WebPushCrypto;
use App\Services\Telegram\TelegramSender;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Уведомления со звуком: опрос колокольчика (число и самое новое), Web Push (подписка, шифрование,
 * удаление недействующих подписок), уведомления в CRM нельзя отключить, ключи VAPID командой.
 */
class WebPushTest extends TestCase
{
    use CommsFixtures;

    private array $ua;

    private string $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures();
        $vapid = WebPushCrypto::generateKeys();
        config(['fluggi.webpush.public_key' => $vapid['public'], 'fluggi.webpush.private_key' => $vapid['private'], 'fluggi.webpush.subject' => 'mailto:ceo@fluggi.uz']);
        $this->ua = WebPushCrypto::generateKeys();
        $this->auth = random_bytes(16);
    }

    private function subscription(User $user, string $endpoint = 'https://fcm.googleapis.com/fcm/send/device-1'): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => $this->ua['public'], 'auth' => WebPushCrypto::b64($this->auth)]];
    }

    public function test_unread_poll_returns_count_and_newest_own_notification(): void
    {
        $this->actingAsUser($this->manager)->getJson('/notifications/unread-count')->assertOk()->assertExactJson(['count' => 0, 'latest' => null]);
        Notifier::notify([$this->manager->id], 'lead.created', ['Старое', null], '/sales/leads');
        $this->travel(2)->seconds();
        Notifier::notify([$this->manager->id], 'lead.assigned', ['Новый лид', 'Alfa'], '/sales/leads/1');
        Notifier::notify([$this->rop->id], 'lead.created', ['Чужое', null], '/x');
        $newest = Notification::where('user_id', $this->manager->id)->where('title', 'Новый лид')->firstOrFail();
        $r = $this->actingAsUser($this->manager)->getJson('/notifications/unread-count')->assertOk()->json();
        $this->assertSame(2, $r['count']);
        $this->assertSame(['id' => $newest->id, 'title' => 'Новый лид', 'body' => 'Alfa', 'url' => '/notifications/'.$newest->id.'/open'], array_diff_key($r['latest'], ['at' => 1]));
        // Прочитанное не попадает в окно
        $this->actingAsUser($this->manager)->postJson('/notifications/'.$newest->id.'/read')->assertNoContent();
        $this->assertSame('Старое', $this->actingAsUser($this->manager)->getJson('/notifications/unread-count')->json('latest.title'));
        // Без входа — нет данных
        $this->flushSession();
        $this->getJson('/notifications/unread-count')->assertUnauthorized();
    }

    public function test_layout_has_sound_banner_and_push_prompt_without_mute_toggle(): void
    {
        $html = $this->actingAsUser($this->manager)->get('/notifications')->assertOk()->getContent();
        $this->assertStringContainsString(t('notifications.sound.unlock'), $html);
        $this->assertStringContainsString(t('notifications.push.enable'), $html);
        $this->assertStringContainsString('notify.wav', $html);
        $this->assertFileExists(public_path('sounds/notify.wav'));
        // В личных настройках нет выключателя уведомлений в CRM
        $profile = $this->actingAsUser($this->manager)->get('/profile')->assertOk()->getContent();
        $this->assertStringNotContainsString("'IN_APP'", $profile);
        $this->assertStringContainsString(t('telegram.inAppAlwaysOn'), $profile);
    }

    public function test_in_app_cannot_be_disabled(): void
    {
        NotificationSetting::insert(['user_id' => $this->manager->id, 'event_type' => 'lead.created', 'channel' => 'IN_APP', 'enabled' => false]);
        Notifier::notify([$this->manager->id], 'lead.created', ['Лид', null]);
        $this->assertSame(1, Notification::where('user_id', $this->manager->id)->count());
        $this->actingAsUser($this->manager)->putJson('/notifications/settings', ['settings' => [
            ['eventType' => 'lead.assigned', 'channel' => 'IN_APP', 'enabled' => false],
            ['eventType' => 'lead.assigned', 'channel' => 'TELEGRAM', 'enabled' => false],
        ]])->assertOk();
        $this->assertFalse(NotificationSetting::where(['user_id' => $this->manager->id, 'event_type' => 'lead.assigned', 'channel' => 'IN_APP'])->exists());
        $this->assertTrue(NotificationSetting::where(['user_id' => $this->manager->id, 'event_type' => 'lead.assigned', 'channel' => 'TELEGRAM', 'enabled' => false])->exists());
    }

    public function test_subscribe_validation_and_ownership(): void
    {
        $sub = $this->subscription($this->manager);
        // Адрес не push-сервиса (защита от запросов сервера на произвольные адреса), неверные ключи
        $this->actingAsUser($this->manager)->postJson('/me/push-subscriptions', ['endpoint' => 'https://evil.example.com/x', 'keys' => $sub['keys']])
            ->assertStatus(422)->assertJsonValidationErrors('endpoint');
        $this->actingAsUser($this->manager)->postJson('/me/push-subscriptions', ['endpoint' => 'http://fcm.googleapis.com/x', 'keys' => $sub['keys']])
            ->assertStatus(422)->assertJsonValidationErrors('endpoint');
        $this->actingAsUser($this->manager)->postJson('/me/push-subscriptions', ['endpoint' => $sub['endpoint'], 'keys' => ['p256dh' => 'AAAA', 'auth' => 'short']])
            ->assertStatus(422)->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);
        $this->actingAsUser($this->manager)->postJson('/me/push-subscriptions', ['endpoint' => $sub['endpoint']])->assertStatus(422);

        $this->actingAsUser($this->manager)->postJson('/me/push-subscriptions', $sub)->assertCreated();
        $this->actingAsUser($this->manager)->postJson('/me/push-subscriptions', $sub)->assertCreated();
        $this->assertSame(1, PushSubscription::count());
        $this->assertSame(hash('sha256', $sub['endpoint']), PushSubscription::value('endpoint_hash'));
        $this->assertTrue(AuditLog::where('action', 'push.subscribed')->exists());

        // Чужой не может отписать
        $this->actingAsUser($this->rop)->deleteJson('/me/push-subscriptions', ['endpoint' => $sub['endpoint']])->assertNoContent();
        $this->assertSame($this->manager->id, PushSubscription::value('user_id'));
        // Тот же браузер под другим сотрудником — подписка переходит к нему
        $this->actingAsUser($this->rop)->postJson('/me/push-subscriptions', $sub)->assertCreated();
        $this->assertSame([$this->rop->id], PushSubscription::pluck('user_id')->all());
        $this->actingAsUser($this->rop)->deleteJson('/me/push-subscriptions', ['endpoint' => $sub['endpoint']])->assertNoContent();
        $this->assertSame(0, PushSubscription::count());

        // Ключи VAPID не заданы — подписка недоступна
        config(['fluggi.webpush.private_key' => null]);
        $this->actingAsUser($this->manager)->postJson('/me/push-subscriptions', $sub)->assertNotFound();
    }

    public function test_push_delivery_is_encrypted_for_the_subscription_and_signed_with_vapid(): void
    {
        $this->actingAsUser($this->manager)->postJson('/me/push-subscriptions', $this->subscription($this->manager))->assertCreated();
        $this->actingAsUser($this->manager)->postJson('/me/push-subscriptions', $this->subscription($this->manager, 'https://web.push.apple.com/QGuQyavXutnMH'))->assertCreated();
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201), 'web.push.apple.com/*' => Http::response('', 201)]);

        Notifier::notify([$this->manager->id, $this->rop->id], 'lead.assigned', ['Новый лид', 'Alfa → вам'], '/sales/leads/42');
        // Push — только тому, у кого есть подписка; Telegram-очередь его не трогает
        $this->assertSame(1, NotificationDelivery::where('channel', 'PUSH')->count());
        $this->assertSame(0, NotificationDelivery::where('channel', 'PUSH')->where('user_id', $this->rop->id)->count());
        config(['fluggi.telegram.token' => '123:test']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        TelegramSender::processBatch();
        $this->assertSame('PENDING', NotificationDelivery::where('channel', 'PUSH')->value('status'));

        $this->assertSame(1, PushSender::processBatch());
        $d = NotificationDelivery::where('channel', 'PUSH')->firstOrFail();
        $this->assertSame(['SENT', 1], [$d->status, $d->attempts]);
        $sent = Http::recorded(fn (Request $r) => ! str_contains($r->url(), 'telegram'))->map(fn ($pair) => $pair[0]);
        $this->assertCount(2, $sent);
        $req = $sent->first(fn (Request $r) => str_starts_with($r->url(), 'https://fcm.googleapis.com/'));
        $this->assertSame('aes128gcm', $req->header('Content-Encoding')[0]);
        $this->assertSame('high', $req->header('Urgency')[0]);
        $this->assertStringStartsWith('vapid t=', $req->header('Authorization')[0]);
        $this->assertStringEndsWith('k='.config('fluggi.webpush.public_key'), $req->header('Authorization')[0]);
        $payload = json_decode(WebPushCrypto::decrypt($req->body(), $this->ua, $this->auth), true);
        $this->assertSame(['title' => 'Новый лид', 'body' => 'Alfa → вам', 'url' => '/sales/leads/42', 'tag' => 'fluggi-'.$d->id,
            'icon' => '/brand/icon-192', 'badge' => '/brand/icon-192'], $payload);
        $this->assertNotNull(PushSubscription::first()->last_success_at);
    }

    public function test_dead_subscriptions_are_removed_and_transient_errors_retried(): void
    {
        $this->actingAsUser($this->manager)->postJson('/me/push-subscriptions', $this->subscription($this->manager))->assertCreated();
        $this->actingAsUser($this->manager)->postJson('/me/push-subscriptions', $this->subscription($this->manager, 'https://updates.push.services.mozilla.com/wpush/v2/gone'))->assertCreated();
        Http::fake([
            'fcm.googleapis.com/*' => Http::sequence()->push('busy', 503)->push('', 201)->whenEmpty(Http::response('', 404)),
            'updates.push.services.mozilla.com/*' => Http::response('', 410),
        ]);
        Notifier::notify([$this->manager->id], 'task.assigned', ['Задача', null], '/tasks/1');
        PushSender::processBatch();
        // 410 — подписка удалена; 503 — повтор позже
        $this->assertSame(['https://fcm.googleapis.com/fcm/send/device-1'], PushSubscription::pluck('endpoint')->all());
        $d = NotificationDelivery::where('channel', 'PUSH')->firstOrFail();
        $this->assertSame(['PENDING', 1], [$d->status, $d->attempts]);
        $this->assertStringContainsString('HTTP 503', $d->last_error);
        $this->assertSame(0, PushSender::processBatch());
        $d->update(['next_attempt_at' => now()->subSecond()]);
        PushSender::processBatch();
        $this->assertSame('SENT', $d->fresh()->status);

        // Все подписки недействительны (дальше — 404) — доставка FAILED, подписок не осталось
        Notifier::notify([$this->manager->id], 'task.assigned', ['Ещё задача', null]);
        PushSender::processBatch();
        $this->assertSame(0, PushSubscription::count());
        $this->assertSame('FAILED', NotificationDelivery::where('channel', 'PUSH')->orderByDesc('created_at')->orderByDesc('id')->get()->firstWhere('title', 'Ещё задача')->status);
        // Новых push без подписки не создаётся
        Notifier::notify([$this->manager->id], 'task.assigned', ['Третья', null]);
        $this->assertSame(0, NotificationDelivery::where('channel', 'PUSH')->where('title', 'Третья')->count());
    }

    public function test_vapid_key_command_is_idempotent(): void
    {
        $env = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($env, "APP_NAME=Fluggi\nVAPID_PUBLIC_KEY=\nVAPID_PRIVATE_KEY=\n");
        try {
            $this->assertSame(0, Artisan::call('fluggi:webpush-keys', ['--env' => $env]));
            $first = file_get_contents($env);
            $this->assertMatchesRegularExpression('/^VAPID_PUBLIC_KEY=B[\w-]{86}$/m', $first);
            $this->assertMatchesRegularExpression('/^VAPID_PRIVATE_KEY=[\w-]{43}$/m', $first);
            $this->assertStringContainsString('APP_NAME=Fluggi', $first);
            preg_match('/^VAPID_PUBLIC_KEY=(.+)$/m', $first, $pub);
            preg_match('/^VAPID_PRIVATE_KEY=(.+)$/m', $first, $priv);
            // Ключи рабочие: подпись проверяется открытым ключом
            $auth = WebPushCrypto::vapidAuthorization('https://fcm.googleapis.com/x', 'mailto:a@b.uz', $pub[1], $priv[1]);
            $this->assertStringContainsString('k='.$pub[1], $auth);

            // Повторный запуск не меняет ключи
            $this->assertSame(0, Artisan::call('fluggi:webpush-keys', ['--env' => $env]));
            $this->assertSame($first, file_get_contents($env));
            $this->assertSame(1, substr_count(file_get_contents($env), 'VAPID_PUBLIC_KEY='));

            // Файл без строк VAPID — дописываются
            file_put_contents($env, "APP_NAME=Fluggi\n");
            Artisan::call('fluggi:webpush-keys', ['--env' => $env]);
            $this->assertMatchesRegularExpression('/^VAPID_PRIVATE_KEY=[\w-]{43}$/m', file_get_contents($env));
            // Только один ключ — ошибка, ничего не меняется
            file_put_contents($env, "VAPID_PUBLIC_KEY=abc\n");
            $this->assertSame(1, Artisan::call('fluggi:webpush-keys', ['--env' => $env]));
            $this->assertSame("VAPID_PUBLIC_KEY=abc\n", file_get_contents($env));
            $this->assertSame(0, Artisan::call('fluggi:webpush-keys', ['--show' => true]));
            $this->assertStringContainsString('VAPID_PRIVATE_KEY=', Artisan::output());
        } finally {
            @unlink($env);
        }
    }
}
