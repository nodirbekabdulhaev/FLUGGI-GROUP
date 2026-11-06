<?php

namespace App\Services\Telegram;

use App\Auth\Access;
use App\Auth\Sessions;
use App\Exceptions\BusinessRule;
use App\Models\Setting;
use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Support\Lang;
use App\Support\Secrets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    /** @var array<string, callable(string):string> префикс callback_data → обработчик (ответ — текст всплывающего сообщения) */
    private static array $callbacks = [];

    /**
     * Обработчик нажатий inline-кнопок с callback_data «<префикс>:<аргумент>». Выполняется с правами
     * сотрудника, к которому привязан чат (App\Auth\Sessions::accessFor) — как действие в CRM.
     */
    public static function onCallback(string $prefix, callable $handler): void
    {
        self::$callbacks[$prefix] = $handler;
    }

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

            if ($err->network) {
                return t('telegram.problems.network', ['reason' => mb_substr($err->description, 0, 300)]);
            }

            return t('telegram.problems.apiError', ['error' => $err->description]);
        }
        $reason = Secrets::redact($err->getMessage());

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
        return Lang::get('telegram.botReplies.'.$key, $params + ['app' => TelegramClient::escape(brand()->name())], $locale ?? config('app.locale', 'ru'));
    }

    private static function answer(string $callbackId, string $text): void
    {
        try {
            TelegramClient::answerCallbackQuery($callbackId, $text);
        } catch (Throwable $e) {
            Log::warning('Telegram answerCallbackQuery: '.$e->getMessage());
        }
    }

    /**
     * Нажатие inline-кнопки. Кто нажал — по from.id (это и есть id личного чата с ботом): чат должен быть
     * привязан к активному сотруднику, права — его права в CRM; иначе действие не выполняется.
     */
    private static function handleCallback(array $cq): void
    {
        $callbackId = (string) ($cq['id'] ?? '');
        $fromId = (string) ($cq['from']['id'] ?? '');
        $data = (string) ($cq['data'] ?? '');
        if ($callbackId === '') {
            return;
        }
        $user = $fromId !== '' ? User::with(['role', 'team'])->where('telegram_chat_id', $fromId)->where('status', 'ACTIVE')->first() : null;
        if (! $user) {
            self::answer($callbackId, self::say('callbackNotLinked'));

            return;
        }
        $locale = in_array($user->locale, Lang::LOCALES, true) ? $user->locale : Lang::FALLBACK;
        [$prefix, $arg] = array_pad(explode(':', $data, 2), 2, '');
        $handler = self::$callbacks[$prefix] ?? null;
        if (! $handler) {
            self::answer($callbackId, self::say('callbackUnknown', [], $locale));

            return;
        }
        $previousAccess = app()->bound(Access::class) ? app(Access::class) : null;
        $previousLocale = app()->getLocale();
        app()->instance(Access::class, Sessions::accessFor($user));
        app()->setLocale($locale);
        $done = false;
        try {
            $text = $handler($arg);
            $done = true;
        } catch (HttpException $e) {
            $text = self::say($e->getStatusCode() === 404 ? 'callbackNotFound' : 'callbackForbidden', [], $locale);
        } catch (BusinessRule $e) {
            $text = $e->getMessage();
        } catch (Throwable $e) {
            Log::error('Telegram callback '.$prefix.': '.$e->getMessage());
            $text = self::say('callbackFailed', [], $locale);
        } finally {
            $previousAccess ? app()->instance(Access::class, $previousAccess) : app()->forgetInstance(Access::class);
            app()->setLocale($previousLocale);
        }
        self::answer($callbackId, strip_tags($text));
        // Выполнено — кнопку убираем, чтобы не нажимать повторно
        $chatId = (string) ($cq['message']['chat']['id'] ?? '');
        $messageId = (int) ($cq['message']['message_id'] ?? 0);
        if ($done && $chatId !== '' && $messageId > 0) {
            try {
                TelegramClient::clearKeyboard($chatId, $messageId);
            } catch (Throwable $e) {
                Log::warning('Telegram editMessageReplyMarkup: '.$e->getMessage());
            }
        }
    }

    /** Обработка входящего сообщения боту (webhook). */
    public static function handleUpdate(array $update): void
    {
        if (is_array($update['callback_query'] ?? null)) {
            self::handleCallback($update['callback_query']);

            return;
        }
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
