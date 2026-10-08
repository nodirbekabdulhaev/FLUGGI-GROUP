<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Setting;
use App\Models\TelegramAccount;
use App\Support\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    public const MAX_ATTEMPTS = 3;

    public function token(): ?string
    {
        return Setting::get('telegram.bot_token') ?: config('services.telegram.bot_token');
    }

    public function enabled(): bool
    {
        return (bool) $this->token();
    }

    /** @return array{0:bool,1:?string} [ok, error] */
    public function send(int $chatId, string $text): array
    {
        if (! $this->enabled()) {
            return [false, 'TELEGRAM_BOT_TOKEN не настроен'];
        }

        try {
            $res = Http::timeout(10)->asForm()->post("https://api.telegram.org/bot{$this->token()}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
            ]);
            if ($res->successful() && $res->json('ok')) {
                return [true, null];
            }
            $err = $res->json('description') ?? ('HTTP '.$res->status());
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }

        // never leak the bot token into logs
        $err = str_replace((string) $this->token(), '***', (string) $err);
        Log::channel('telegram')->error('Telegram send failed', ['chat_id' => $chatId, 'error' => $err]);

        return [false, $err];
    }

    public function deliver(Notification $n): bool
    {
        [$ok, $err] = $this->send((int) $n->chat_id, $n->body);
        $n->attempts++;

        if ($ok) {
            $n->fill(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
        } elseif ($n->attempts >= self::MAX_ATTEMPTS) {
            $n->fill(['status' => 'failed', 'error' => $err]);
        } else {
            // back-off and retry on a later cron tick
            $n->fill(['status' => 'pending', 'error' => $err, 'scheduled_at' => now()->addMinutes(5 * $n->attempts)]);
        }
        $n->save();

        if ($n->status === 'failed') {
            app(NotificationService::class)->toStaff('settings.manage', 'system', 'Ошибка Telegram', (string) $err, '/notifications/telegram', null, 'tgfail:'.$n->id);
        }

        return $ok;
    }

    /** Process the queue for the current tenant. Returns [sent, failed]. */
    public function processQueue(int $limit = 50): array
    {
        $sent = $failed = 0;
        Notification::where('channel', 'telegram')->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
            ->orderBy('id')->limit($limit)->get()
            ->each(function (Notification $n) use (&$sent, &$failed) {
                $this->deliver($n) ? $sent++ : $failed++;
            });

        return [$sent, $failed];
    }

    public function retry(Notification $n): void
    {
        $n->update(['status' => 'pending', 'attempts' => 0, 'error' => null, 'scheduled_at' => now()]);
    }

    /** Handle an incoming bot update: "/start <token>" links the Telegram user to an ERP entity. */
    public function handleUpdate(array $update): void
    {
        $msg = $update['message'] ?? null;
        if (! $msg || empty($msg['from']['id'])) {
            return;
        }
        $chatId = (int) $msg['chat']['id'];
        $text = trim((string) ($msg['text'] ?? ''));

        if (preg_match('/^\/start\s+([a-z0-9]{8,32})$/i', $text, $m)) {
            $acc = TelegramAccount::withoutGlobalScopes()->where('token', strtolower($m[1]))->first();
            if (! $acc) {
                $this->send($chatId, '❌ Ссылка недействительна или устарела.');

                return;
            }
            // Telegram numeric ID (not username) is the identity (spec §33)
            TelegramAccount::withoutGlobalScopes()->where('telegram_user_id', $msg['from']['id'])->where('id', '!=', $acc->id)->delete();
            $acc->update([
                'telegram_user_id' => $msg['from']['id'],
                'chat_id' => $chatId,
                'username' => $msg['from']['username'] ?? null,
                'linked_at' => now(),
                'token' => null,
            ]);
            $this->send($chatId, '✅ Telegram подключён. Вы будете получать уведомления учебного центра.');

            return;
        }

        if (str_starts_with($text, '/start')) {
            $this->send($chatId, 'Здравствуйте! Чтобы подключить уведомления, откройте персональную ссылку из ERP.');
        }
    }
}
