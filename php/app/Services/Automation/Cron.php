<?php

namespace App\Services\Automation;

use App\Services\Push\PushSender;
use App\Services\Push\WebPush;
use App\Services\Smm\InstagramPublisher;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramSender;
use App\Services\Telegram\TelegramService;
use App\Support\Outbox;

/**
 * Фоновая работа на хостинге без постоянных процессов: cron (schedule:run или POST hooks/cron)
 * раз в минуту доставляет события outbox, отправляет очередь Telegram и запускает задачи
 * планировщика. Повторные и параллельные вызовы безопасны: строки очередей блокируются,
 * задачи планировщика выполняются один раз за слот (job_runs).
 */
final class Cron
{
    /** Telegram- и push-очереди + проверка бота + планировщик (outbox — отдельной задачей в routes/console.php). */
    public static function comms(float $budgetSeconds = 40): array
    {
        $started = microtime(true);
        $telegram = 0;
        if (TelegramClient::configured()) {
            $telegram = TelegramSender::drain(max(1, $budgetSeconds - (microtime(true) - $started)));
            TelegramService::checkIfDue();
        }
        $push = 0;
        if (WebPush::configured()) {
            $push = PushSender::drain(max(1, $budgetSeconds - (microtime(true) - $started)));
        }
        Scheduler::tick();

        return ['telegram' => $telegram, 'push' => $push];
    }

    /** Всё сразу — для HTTP-cron (hooks/cron) в пределах бюджета времени. */
    public static function all(float $budgetSeconds = 40): array
    {
        $started = microtime(true);
        $events = Outbox::drain((int) max(1, floor($budgetSeconds)));
        $left = max(1, $budgetSeconds - (microtime(true) - $started));
        $r = self::comms($left);
        $instagram = InstagramPublisher::tick(2);
        // События, созданные задачами планировщика (task.overdue и т.п.), доставляются сразу
        $events += Outbox::drain((int) max(1, floor($budgetSeconds - (microtime(true) - $started))));

        return ['events' => $events, 'telegram' => $r['telegram'], 'push' => $r['push'], 'instagram' => $instagram['started'] + $instagram['checked'], 'ms' => (int) round((microtime(true) - $started) * 1000)];
    }
}
