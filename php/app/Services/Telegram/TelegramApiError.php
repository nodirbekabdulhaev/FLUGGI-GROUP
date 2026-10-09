<?php

namespace App\Services\Telegram;

use RuntimeException;

/** Ошибка Telegram Bot API: HTTP-статус, описание, пауза перед повтором (429). */
final class TelegramApiError extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $description, public readonly ?int $retryAfter = null)
    {
        parent::__construct('Telegram '.$status.': '.$description);
    }

    /** Бот заблокирован пользователем или чат не найден — повторять бессмысленно. */
    public function permanent(): bool
    {
        return $this->status === 403 || $this->status === 400;
    }
}
