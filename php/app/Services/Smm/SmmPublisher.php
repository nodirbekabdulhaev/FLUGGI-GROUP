<?php

namespace App\Services\Smm;

/**
 * Автопубликация SMM — один запуск cron раз в минуту: Instagram (контейнеры Meta и проверка статуса),
 * Telegram-каналы (отправка файла и подписи), прерванные сбоем места и уборка файлов.
 */
final class SmmPublisher
{
    public static function tick(int $limit = 5): array
    {
        $ig = InstagramPublisher::run($limit);
        $tg = TelegramPublisher::run($limit);

        return ['instagram' => $ig, 'telegram' => $tg, 'stale' => SmmTargets::failStale(), 'cleaned' => SmmTargets::cleanup()];
    }
}
