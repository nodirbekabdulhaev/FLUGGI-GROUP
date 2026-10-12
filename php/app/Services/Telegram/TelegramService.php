<?php

namespace App\Services\Telegram;

use App\Exceptions\BusinessRule;
use App\Models\Setting;
use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Support\Lang;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Привязка Telegram к аккаунту (ТЗ §53): CRM выдаёт одноразовый код, сотрудник отправляет
 * боту /start <код>, webhook сохраняет telegram_chat_id. Фоновых процессов на хостинге нет:
 * состояние бота (getMe + getWebhookInfo) проверяет cron и записывает в settings.
 */
final class TelegramService
{
    public const LINK_TTL_MINUTES = 15;

    public const HEALTH_KEY = 'telegram.health';

    /** Проверка состояния не чаще раза в 5 минут; старше 15 минут — cron не работает. */
    public const CHECK_EVERY_SECONDS = 300;

    public const STALE_SECONDS = 900;

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Понятная причина сбоя Telegram для CEO. */
    public static function explain(Throwable $err): string
    {
        if ($err instanceof TelegramApiError) {
            if ($err->status === 401 || $err->status === 404) {
                return t('telegram.problems.badToken');
            }
            if ($err->status === 409) {
                return t('telegram.problems.conflict');
            }

            return t('telegram.problems.apiError', ['error' => $err->description]);
        }
        $reason = $err instanceof ConnectionException ? $err->getMessage() : $err->getMessage();

        return t('telegram.problems.network', ['reason' => mb_substr($reason, 0, 300)]);
    }

    /** @return array{at:string,problem:?string,bot:?string}|null */
    public static function health(): ?array
    {
        $v = Setting::find(self::HEALTH_KEY)?->value;

        return is_array($v) ? $v : null;
    }

    public static function reportHealth(?string $problem, ?string $bot): void
    {
        Setting::updateOrCreate(['key' => self::HEALTH_KEY], ['value' => [
            'at' => now()->toIso8601ZuluString('millisecond'), 'problem' => $problem, 'bot' => $bot,
        ]]);
    }

    /**
     * Проверка токена, имени бота и webhook. Результат сохраняется в БД: профиль показывает
     * причину сбоя, а если проверок давно не было — что cron не запущен.
     */
    public static function check(): bool
    {
        if (! TelegramClient::configured()) {
            return false;
        }
        $problem = null;
        $bot = self::health()['bot'] ?? null;
        try {
            $bot = (string) (TelegramClient::getMe()['username'] ?? '');
            $configured = TelegramClient::configuredUsername();
            if ($configured && strcasecmp($configured, $bot) !== 0) {
                $problem = t('telegram.problems.usernameMismatch', ['configured' => $configured, 'bot' => $bot]);
            }
            if (! $problem) {
                $info = TelegramClient::getWebhookInfo();
                $expected = self::webhookUrl();
                if (empty($info['url'])) {
                    $problem = t('telegram.problems.noWebhook');
                } elseif ($info['url'] !== $expected) {
                    $problem = t('telegram.problems.otherWebhook', ['url' => $info['url']]);
                } elseif (! empty($info['last_error_message']) && ($info['last_error_date'] ?? 0) > time() - 3600) {
                    $problem = t('telegram.problems.webhookError', ['error' => $info['last_error_message']]);
                }
            }
        } catch (Throwable $e) {
            $problem = self::explain($e);
        }
        if ($problem) {
            Log::warning('Telegram: '.$problem);
        }
        self::reportHealth($problem, $bot ?: null);

        return $problem === null;
    }

    /** Из cron: проверка не чаще раза в 5 минут. */
    public static function checkIfDue(): void
    {
        $h = self::health();
        if (! $h || CarbonImmutable::parse($h['at'])->diffInSeconds(now(), true) >= self::CHECK_EVERY_SECONDS) {
            self::check();
        }
    }

    public static function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/hooks/telegram';
    }

    /** Почему бот может не отвечать — показывается в профиле. */
    public static function currentProblem(): ?string
    {
        if (! TelegramClient::configured()) {
            return null;
        }
        $h = self::health();
        if (! $h || CarbonImmutable::parse($h['at'])->diffInSeconds(now(), true) > self::STALE_SECONDS) {
            return t('telegram.problems.stale');
        }

        return $h['problem'] ?? null;
    }

    /** Имя бота: настоящее (из getMe), иначе из .env. */
    public static function username(): ?string
    {
        return self::health()['bot'] ?? TelegramClient::configuredUsername();
    }

    /** @return array{botConfigured:bool,botUsername:?string,linked:bool,username:?string,problem:?string} */
    public static function status(User $user): array
    {
        return [
            'botConfigured' => TelegramClient::configured(),
            'botUsername' => self::username(),
            'linked' => (bool) $user->telegram_chat_id,
            'username' => $user->telegram_username,
            'problem' => self::currentProblem(),
        ];
    }

    /** @return array{deepLink:string,command:string,expiresAt:string} */
    public static function createLink(User $user): array
    {
        if (! TelegramClient::configured()) {
            throw new BusinessRule(t('telegram.errors.notConfigured'));
        }
        if ($problem = self::currentProblem()) {
            throw new BusinessRule($problem);
        }
        $bot = self::username();
        if (! $bot) {
            throw new BusinessRule(t('telegram.errors.noUsername'));
        }
        $token = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $expires = now()->addMinutes(self::LINK_TTL_MINUTES);
        TelegramLinkToken::create(['token_hash' => self::hash($token), 'user_id' => $user->id, 'expires_at' => $expires]);

        return [
            'deepLink' => 'https://t.me/'.$bot.'?start='.$token,
            'command' => '/start '.$token,
            'expiresAt' => $expires->toIso8601ZuluString('millisecond'),
        ];
    }

    public static function unlink(User $user): void
    {
        $user->update(['telegram_chat_id' => null, 'telegram_username' => null]);
    }

    private static function reply(string $chatId, string $text): void
    {
        try {
            TelegramClient::sendMessage($chatId, $text);
        } catch (Throwable $e) {
            Log::warning('Telegram reply: '.$e->getMessage());
        }
    }

    /** Ответ бота на языке сотрудника (не подключён — язык системы). */
    private static function say(string $key, array $params = [], ?string $locale = null): string
    {
        return Lang::get('telegram.botReplies.'.$key, $params, $locale ?? config('app.locale', 'ru'));
    }

    /** Обработка входящего сообщения боту (webhook). */
    public static function handleUpdate(array $update): void
    {
        $msg = $update['message'] ?? null;
        if (! is_array($msg) || ! is_string($msg['text'] ?? null) || ($msg['chat']['type'] ?? null) !== 'private') {
            return;
        }
        $chatId = (string) $msg['chat']['id'];
        $parts = preg_split('/\s+/', trim($msg['text']), 3);
        $command = $parts[0] ?? '';
        $arg = $parts[1] ?? null;

        if ($command === '/start' && $arg) {
            $row = TelegramLinkToken::with('user')->where('token_hash', self::hash($arg))->first();
            if (! $row || $row->used_at || $row->expires_at->isPast() || ! $row->user || $row->user->status !== 'ACTIVE') {
                self::reply($chatId, self::say('invalidCode'));

                return;
            }
            DB::transaction(function () use ($row, $chatId, $msg) {
                // Один чат — один аккаунт
                User::where('telegram_chat_id', $chatId)->where('id', '<>', $row->user_id)
                    ->update(['telegram_chat_id' => null, 'telegram_username' => null]);
                User::whereKey($row->user_id)->update(['telegram_chat_id' => $chatId, 'telegram_username' => $msg['from']['username'] ?? null]);
                $row->update(['used_at' => now()]);
            });
            self::reply($chatId, self::say('linked', ['name' => TelegramClient::escape($row->user->full_name)], $row->user->locale));

            return;
        }
        if ($command === '/stop') {
            $locale = User::where('telegram_chat_id', $chatId)->value('locale');
            $n = User::where('telegram_chat_id', $chatId)->update(['telegram_chat_id' => null, 'telegram_username' => null]);
            self::reply($chatId, self::say($n ? 'stopped' : 'notLinked', [], $locale));

            return;
        }
        $linked = User::where('telegram_chat_id', $chatId)->first();
        self::reply($chatId, $linked
            ? self::say('whoami', ['name' => TelegramClient::escape($linked->full_name)], $linked->locale)
            : self::say('hello'));
    }
}
