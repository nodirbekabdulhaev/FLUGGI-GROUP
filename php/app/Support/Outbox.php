<?php

namespace App\Support;

use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transactional outbox (ТЗ §54): событие пишется в одной транзакции с изменением данных,
 * cron доставляет его подписчикам (уведомления, Telegram, пересчёты). Ошибка подписчика →
 * повтор с экспоненциальной задержкой, до 10 попыток.
 */
final class Outbox
{
    private const BATCH = 20;

    private const MAX_ATTEMPTS = 10;

    /** @var array<string, list<callable(array,array):void>> */
    private static array $handlers = [];

    public static function publish(string $type, array $payload, ?string $actorId = null): void
    {
        OutboxEvent::create([
            'type' => $type,
            'payload' => $payload,
            'actor_id' => $actorId ?? access(false)?->id(),
            'available_at' => now(),
        ]);
    }

    /** Подписка на событие; $handler($payload, ['eventId','actorId','createdAt']). */
    public static function on(string $type, callable $handler): void
    {
        self::$handlers[$type][] = $handler;
    }

    public static function forget(): void
    {
        self::$handlers = [];
    }

    /** Обрабатывает одну пачку. Возвращает количество обработанных событий. */
    public static function processBatch(): int
    {
        return DB::transaction(function () {
            $rows = DB::select(
                'SELECT id, type, payload, actor_id, created_at, attempts FROM outbox_events
                 WHERE processed_at IS NULL AND available_at <= UTC_TIMESTAMP(3) AND attempts < ?
                 ORDER BY created_at LIMIT '.self::BATCH.' '.DB::lockRows(),
                [self::MAX_ATTEMPTS],
            );
            foreach ($rows as $row) {
                $payload = json_decode($row->payload, true) ?? [];
                try {
                    foreach (self::$handlers[$row->type] ?? [] as $handler) {
                        $handler($payload, ['eventId' => $row->id, 'actorId' => $row->actor_id, 'createdAt' => $row->created_at]);
                    }
                    DB::table('outbox_events')->where('id', $row->id)->update([
                        'processed_at' => now(), 'attempts' => $row->attempts + 1, 'last_error' => null,
                    ]);
                } catch (Throwable $e) {
                    $attempts = $row->attempts + 1;
                    $delay = min(2 ** $attempts, 3600);
                    Log::warning('Outbox handler failed', ['eventId' => $row->id, 'type' => $row->type, 'attempts' => $attempts, 'error' => $e->getMessage()]);
                    DB::table('outbox_events')->where('id', $row->id)->update([
                        'attempts' => $attempts,
                        'available_at' => now()->addSeconds($delay),
                        'last_error' => mb_substr($e->getMessage(), 0, 1000),
                    ]);
                }
            }

            return count($rows);
        });
    }

    /** Разбирает очередь в пределах бюджета времени (секунд). */
    public static function drain(int $budgetSeconds = 40): int
    {
        $total = 0;
        $until = microtime(true) + $budgetSeconds;
        while (microtime(true) < $until && ($n = self::processBatch()) > 0) {
            $total += $n;
        }

        return $total;
    }
}
