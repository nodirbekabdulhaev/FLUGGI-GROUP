<?php

namespace App\Services\Telegram;

use App\Support\Db as DbSupport;
use App\Support\Lang;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Доставка уведомлений в Telegram (ТЗ §53): очередь notification_deliveries в БД, разбирает
 * cron; повторы с паузой 30 с, 2 мин, 8 мин, 30 мин; журнал ошибок. Сбой Telegram не влияет
 * на бизнес-операции.
 */
final class TelegramSender
{
    public const MAX_ATTEMPTS = 5;

    public const BATCH = 20;

    /** Пауза перед повтором, секунд. */
    public static function backoff(int $attempt): int
    {
        return (int) min(30 * 4 ** ($attempt - 1), 1800);
    }

    public static function processBatch(int $limit = self::BATCH): int
    {
        if (! TelegramClient::configured()) {
            return 0;
        }
        $appUrl = rtrim((string) config('app.url'), '/');

        return DB::transaction(function () use ($limit, $appUrl) {
            $rows = DB::select(
                "SELECT d.id, d.title, d.body, d.link, d.attempts, u.telegram_chat_id, u.locale
                 FROM notification_deliveries d JOIN users u ON u.id = d.user_id
                 WHERE d.status = 'PENDING' AND d.next_attempt_at <= UTC_TIMESTAMP(3)
                 ORDER BY d.created_at LIMIT ".$limit.' '.DbSupport::lockRows(),
            );
            foreach ($rows as $d) {
                $q = DB::table('notification_deliveries')->where('id', $d->id);
                if (! $d->telegram_chat_id) {
                    $q->update(['status' => 'FAILED', 'last_error' => Lang::get('telegram.errors.notLinked', [], $d->locale ?: Lang::FALLBACK)]);

                    continue;
                }
                $locale = in_array($d->locale, Lang::LOCALES, true) ? $d->locale : Lang::FALLBACK;
                $text = implode("\n", array_filter([
                    '<b>'.TelegramClient::escape($d->title).'</b>',
                    $d->body ? TelegramClient::escape($d->body) : null,
                    $d->link ? '<a href="'.$appUrl.$d->link.'">'.Lang::get('telegram.openInCrm', [], $locale).'</a>' : null,
                ]));
                try {
                    TelegramClient::sendMessage($d->telegram_chat_id, $text);
                    $q->update(['status' => 'SENT', 'sent_at' => now(), 'attempts' => $d->attempts + 1]);
                } catch (Throwable $e) {
                    $attempts = $d->attempts + 1;
                    $final = ($e instanceof TelegramApiError && $e->permanent()) || $attempts >= self::MAX_ATTEMPTS;
                    $delay = $e instanceof TelegramApiError && $e->retryAfter ? $e->retryAfter : self::backoff($attempts);
                    $q->update([
                        'attempts' => $attempts,
                        'last_error' => mb_substr($e->getMessage(), 0, 500),
                        'status' => $final ? 'FAILED' : 'PENDING',
                        'next_attempt_at' => now()->addSeconds($delay),
                    ]);
                }
            }

            return count($rows);
        });
    }

    /** Разбирает очередь в пределах бюджета времени (секунд). */
    public static function drain(float $budgetSeconds): int
    {
        $total = 0;
        $until = microtime(true) + $budgetSeconds;
        while (microtime(true) < $until && ($n = self::processBatch()) > 0) {
            $total += $n;
        }

        return $total;
    }
}
