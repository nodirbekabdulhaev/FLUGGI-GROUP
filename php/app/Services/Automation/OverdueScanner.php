<?php

namespace App\Services\Automation;

use App\Domain\Tasks;
use App\Models\OutboxEvent;
use App\Support\Db as DbSupport;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Поиск просроченных задач и постановка уведомлений в outbox (ТЗ §24: «Просрочено 1 / 3 / 7 дней»).
 * Уведомление — при переходе порога 1, 3 и 7 дней, не чаще. Строка задачи блокируется,
 * поэтому параллельные запуски cron безопасны.
 */
final class OverdueScanner
{
    public const STEPS = [1, 3, 7];

    private static function step(int $days): int
    {
        return count(array_filter(self::STEPS, fn ($s) => $days >= $s));
    }

    /** Пора ли напоминать: с прошлого уведомления пройден новый порог. */
    private static function due(?string $deadline, ?string $notifiedAt, DateTimeInterface $now): bool
    {
        if (! $deadline) {
            return false;
        }
        $d = CarbonImmutable::parse($deadline, 'UTC');
        $days = Tasks::taskOverdueDays($d, true, $now);
        $notified = $notifiedAt ? Tasks::taskOverdueDays($d, true, CarbonImmutable::parse($notifiedAt, 'UTC')) : 0;

        return self::step($days) > self::step($notified);
    }

    public static function scan(?DateTimeInterface $now = null): int
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $stamp = $now->format('Y-m-d H:i:s.v');
        $candidates = DB::table('tasks as t')->join('projects as p', 'p.id', '=', 't.project_id')
            ->whereNull('t.deleted_at')->whereNull('p.deleted_at')
            ->whereIn('t.status', ReportService::OPEN_TASK)->where('t.deadline', '<', $stamp)
            ->whereNotIn('p.status', ['COMPLETED', 'CANCELLED'])
            ->limit(500)->get(['t.id', 't.deadline', 't.overdue_notified_at']);
        $sent = 0;
        foreach ($candidates as $c) {
            if (! self::due($c->deadline, $c->overdue_notified_at, $now)) {
                continue;
            }
            $done = DB::transaction(function () use ($c, $now, $stamp) {
                // Перечитываем под блокировкой: другой запуск мог уже отправить уведомление
                $fresh = DB::selectOne('SELECT id, deadline, overdue_notified_at FROM tasks WHERE id = ? '.DbSupport::lockRows(), [$c->id]);
                if (! $fresh || ! self::due($fresh->deadline, $fresh->overdue_notified_at, $now)) {
                    return false;
                }
                DB::table('tasks')->where('id', $c->id)->update(['overdue_notified_at' => $stamp]);
                OutboxEvent::create([
                    'type' => 'task.overdue',
                    'payload' => ['taskId' => $c->id, 'overdueDays' => Tasks::taskOverdueDays(CarbonImmutable::parse($fresh->deadline, 'UTC'), true, $now)],
                    'actor_id' => null,
                    'available_at' => now(),
                ]);

                return true;
            });
            $sent += $done ? 1 : 0;
        }

        return $sent;
    }
}
