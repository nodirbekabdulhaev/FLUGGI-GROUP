<?php

namespace Tests\Feature\People;

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Telegram\TelegramSender;
use App\Services\Telegram\TelegramService;
use App\Support\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Кнопка «Утвердить» в Telegram у CEO: нажатие утверждает день, если чат привязан к сотруднику с правом. */
class AttendanceTelegramTest extends TestCase
{
    /** @var list<array{method:string,body:array}> */
    private array $calls = [];

    private User $ceo;

    private User $manager;

    private Attendance $row;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fluggi.telegram.token' => '123:test', 'fluggi.telegram.webhook_secret' => 'webhook-secret-123', 'app.url' => 'https://crm.test']);
        Http::fake(function (Request $r) {
            $method = basename(parse_url($r->url(), PHP_URL_PATH));
            $this->calls[] = ['method' => $method, 'body' => $r->data()];
            $result = match ($method) {
                'getMe' => ['id' => 1, 'username' => 'fluggi_test_bot'],
                'getWebhookInfo' => ['url' => 'https://crm.test/hooks/telegram', 'pending_update_count' => 0],
                default => ['message_id' => 55],
            };

            return Http::response(['ok' => true, 'result' => $result]);
        });
        TelegramService::check();
        $this->ceo = $this->user('CEO', attrs: ['telegram_chat_id' => '900']);
        $this->manager = $this->user('MANAGER', attrs: ['full_name' => 'Азиз Каримов', 'telegram_chat_id' => '901']);
        $in = CarbonImmutable::parse('2026-10-13 04:00:00', 'UTC');
        $this->row = Attendance::create([
            'user_id' => $this->manager->id, 'date' => '2026-10-13', 'status' => 'PRESENT', 'check_in' => $in, 'check_out' => $in->addHours(9),
            'work_minutes' => 540, 'auto_checkout' => true, 'approval_status' => 'PENDING',
        ]);
        Outbox::publish('attendance.auto_checkout', ['attendanceId' => $this->row->id, 'userId' => $this->manager->id, 'date' => '2026-10-13', 'checkOut' => '18:00']);
        while (Outbox::processBatch() > 0);
        $this->calls = [];
    }

    private function press(int $fromId, string $data, string $secret = 'webhook-secret-123')
    {
        return $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $secret)->postJson('/hooks/telegram', [
            'update_id' => 2,
            'callback_query' => [
                'id' => 'cb-1', 'data' => $data, 'from' => ['id' => $fromId],
                'message' => ['message_id' => 55, 'chat' => ['id' => $fromId, 'type' => 'private']],
            ],
        ]);
    }

    private function answers(): array
    {
        return collect($this->calls)->where('method', 'answerCallbackQuery')->pluck('body.text')->values()->all();
    }

    public function test_ceo_message_has_inline_approve_button_and_callback_approves(): void
    {
        $d = NotificationDelivery::where('user_id', $this->ceo->id)->where('type', 'attendance.auto_checkout')->firstOrFail();
        $this->assertSame([[['text' => 'Утвердить', 'callback_data' => 'att:a:'.$this->row->id]]], json_decode($d->buttons, true));
        // Сотруднику — без кнопки
        $this->assertNull(NotificationDelivery::where('user_id', $this->manager->id)->where('type', 'attendance.auto_checkout')->value('buttons'));

        TelegramSender::processBatch();
        $sent = collect($this->calls)->where('method', 'sendMessage')->firstWhere('body.chat_id', '900');
        $this->assertStringContainsString('Азиз Каримов не отметил уход 13.10.2026. Учтено до 18:00. Утвердите день', $sent['body']['text']);
        $this->assertSame('att:a:'.$this->row->id, $sent['body']['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $this->assertArrayNotHasKey('reply_markup', collect($this->calls)->where('method', 'sendMessage')->firstWhere('body.chat_id', '901')['body']);

        $this->calls = [];
        $this->press(900, 'att:a:'.$this->row->id)->assertOk();
        $this->row->refresh();
        $this->assertSame('APPROVED', $this->row->approval_status);
        $this->assertSame($this->ceo->id, $this->row->approved_by_id);
        $this->assertTrue(AuditLog::where('action', 'attendance.checkout_approve')->where('entity_id', $this->row->id)->where('actor_id', $this->ceo->id)->exists());
        $this->assertSame(['Утверждено: Азиз Каримов, 13.10.2026'], $this->answers());
        $edit = collect($this->calls)->firstWhere('method', 'editMessageReplyMarkup');
        $this->assertSame(['chat_id' => '900', 'message_id' => 55, 'reply_markup' => ['inline_keyboard' => []]], $edit['body']);

        // Повторное нажатие — решение уже принято
        $this->calls = [];
        $this->press(900, 'att:a:'.$this->row->id)->assertOk();
        $this->assertSame([t('attendance.checkout.errors.decided')], $this->answers());
    }

    public function test_unauthorized_or_unlinked_chat_cannot_approve(): void
    {
        // Чат сотрудника без права attendance.manage на всю компанию
        $this->press(901, 'att:a:'.$this->row->id)->assertOk();
        // Чат не привязан
        $this->press(999, 'att:a:'.$this->row->id)->assertOk();
        // Заблокированный CEO
        $blocked = $this->user('CEO', attrs: ['telegram_chat_id' => '902', 'status' => 'BLOCKED']);
        $this->press(902, 'att:a:'.$this->row->id)->assertOk();
        // Неизвестная кнопка, чужой секрет webhook
        $this->press(900, 'zzz:1')->assertOk();
        $this->press(900, 'att:a:'.$this->row->id, 'wrong')->assertForbidden();

        $this->assertSame('PENDING', $this->row->fresh()->approval_status);
        $this->assertSame([
            'Недостаточно прав — действие не выполнено.',
            'Этот чат не подключён к CRM — действие не выполнено.',
            'Этот чат не подключён к CRM — действие не выполнено.',
            'Кнопка устарела.',
        ], $this->answers());
        $this->assertNull(collect($this->calls)->firstWhere('method', 'editMessageReplyMarkup'));
        $this->assertNotNull($blocked);
    }
}
