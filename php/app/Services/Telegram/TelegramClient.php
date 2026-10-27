<?php

namespace App\Services\Telegram;

use App\Support\Secrets;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** Тонкий клиент Telegram Bot API (Laravel HTTP client, без сторонних библиотек). */
final class TelegramClient
{
    public const API_BASE = 'https://api.telegram.org';

    public static function configured(): bool
    {
        return (bool) config('fluggi.telegram.token');
    }

    /** Имя бота из .env (без @). */
    public static function configuredUsername(): ?string
    {
        $name = ltrim(trim((string) config('fluggi.telegram.username')), '@');

        return $name !== '' ? $name : null;
    }

    /**
     * @throws TelegramApiError
     * @throws ConnectionException
     */
    public static function call(string $method, array $body = [], int $timeout = 15): mixed
    {
        $token = config('fluggi.telegram.token');
        if (! $token) {
            throw new TelegramApiError(0, t('telegram.problems.notConfigured'));
        }
        try {
            $res = self::http($timeout)->asJson()->post(self::API_BASE.'/bot'.$token.'/'.$method, $body ?: (object) []);
        } catch (ConnectionException $e) {
            throw self::networkError($e);
        }
        $data = $res->json() ?? [];
        if (! $res->successful() || empty($data['ok'])) {
            throw new TelegramApiError($res->status(), (string) ($data['description'] ?? $res->reason()), $data['parameters']['retry_after'] ?? null);
        }

        return $data['result'] ?? null;
    }

    /**
     * Запрос с файлами (multipart/form-data): $files — имя поля → [путь, имя файла]. Остальные поля — строки.
     *
     * @param  array<string, scalar>  $fields
     * @param  array<string, array{0:string, 1:string}>  $files
     *
     * @throws TelegramApiError
     * @throws ConnectionException
     */
    public static function upload(string $method, array $fields, array $files, int $timeout = 600): mixed
    {
        $token = config('fluggi.telegram.token');
        if (! $token) {
            throw new TelegramApiError(0, t('telegram.problems.notConfigured'));
        }
        $req = self::http($timeout);
        $handles = [];
        try {
            foreach ($files as $name => [$path, $filename]) {
                $fh = fopen($path, 'rb');
                if (! $fh) {
                    throw new TelegramApiError(0, 'file '.$filename);
                }
                $handles[] = $fh;
                $req = $req->attach($name, $fh, $filename);
            }
            $res = $req->post(self::API_BASE.'/bot'.$token.'/'.$method, array_map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, $fields));
        } catch (ConnectionException $e) {
            throw self::networkError($e);
        } finally {
            foreach ($handles as $fh) {
                if (is_resource($fh)) {
                    fclose($fh);
                }
            }
        }
        $data = $res->json() ?? [];
        if (! $res->successful() || empty($data['ok'])) {
            throw new TelegramApiError($res->status(), (string) ($data['description'] ?? $res->reason()), $data['parameters']['retry_after'] ?? null);
        }

        return $data['result'] ?? null;
    }

    /**
     * HTTP-клиент для Bot API: только IPv4 (на хостинге IPv6-маршрут к api.telegram.org бывает недоступен —
     * тогда cURL ждёт до тайм-аута SSL), запас на установку соединения.
     */
    private static function http(int $timeout): PendingRequest
    {
        return Http::timeout($timeout)->connectTimeout(30)->acceptJson()
            ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]]);
    }

    /** Ошибка сети без адреса запроса: в тексте cURL есть URL с токеном бота. */
    private static function networkError(ConnectionException $e): TelegramApiError
    {
        $msg = Secrets::redact($e->getMessage());
        $msg = (string) preg_replace('~\s*for https?://\S+~', '', $msg);

        return new TelegramApiError(0, t('telegram.errors.network', ['error' => mb_substr($msg, 0, 300)]), null, true);
    }

    /** id бота — числовая часть токена до двоеточия. */
    public static function botId(): ?int
    {
        $id = strstr((string) config('fluggi.telegram.token'), ':', true);

        return $id !== false && ctype_digit($id) ? (int) $id : null;
    }

    /** @param  list<list<array{text:string,callback_data:string}>>|null  $keyboard  inline-кнопки */
    public static function sendMessage(string $chatId, string $html, ?array $keyboard = null): mixed
    {
        return self::call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ] + ($keyboard ? ['reply_markup' => ['inline_keyboard' => $keyboard]] : []));
    }

    /** Ответ на нажатие inline-кнопки (всплывающий текст). */
    public static function answerCallbackQuery(string $callbackId, string $text, bool $alert = false): mixed
    {
        return self::call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => mb_substr($text, 0, 200), 'show_alert' => $alert]);
    }

    /** Убрать inline-кнопки у сообщения (действие выполнено). */
    public static function clearKeyboard(string $chatId, int $messageId): mixed
    {
        return self::call('editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $messageId, 'reply_markup' => ['inline_keyboard' => []]]);
    }

    /** @return array{username:string} */
    public static function getMe(): array
    {
        return (array) self::call('getMe');
    }

    public static function getWebhookInfo(): array
    {
        return (array) self::call('getWebhookInfo');
    }

    public static function setWebhook(string $url, string $secret): mixed
    {
        return self::call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => false,
        ]);
    }

    public static function deleteWebhook(): mixed
    {
        return self::call('deleteWebhook');
    }

    /** Экранирование для parse_mode=HTML. */
    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
