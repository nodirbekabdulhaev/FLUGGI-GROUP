<?php

namespace App\Services\Smm;

use RuntimeException;

/** Ошибка Instagram API (текст — для пользователя). */
final class InstagramError extends RuntimeException
{
    public function __construct(string $message, int $code = 0, public readonly bool $permission = false)
    {
        parent::__construct($message, $code);
    }
}
