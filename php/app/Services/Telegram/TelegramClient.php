<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
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
        $res = Http::timeout($timeout)->acceptJson()->asJson()
            ->post(self::API_BASE.'/bot'.$token.'/'.$method, $body ?: (object) []);
        $data = $res->json() ?? [];
        if (! $res->successful() || empty($data['ok'])) {
            throw new TelegramApiError($res->status(), (string) ($data['description'] ?? $res->reason()), $data['parameters']['retry_after'] ?? null);
        }

        return $data['result'] ?? null;
    }

    public static function sendMessage(string $chatId, string $html): mixed
    {
        return self::call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
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
            'allowed_updates' => ['message'],
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
