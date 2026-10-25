<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use App\Services\Telegram\TelegramSender;
use App\Support\MySql as DbSupport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Доставка Web Push (канал PUSH очереди notification_deliveries) — разбирает cron вместе с Telegram.
 * Одна строка очереди = одно уведомление сотруднику; отправляется на все его браузеры.
 * Ответ 404/410 — подписка больше не действует и удаляется. Временные сбои (сеть, 429, 5xx) —
 * повтор с той же паузой, что у Telegram, пока ни один браузер не получил сообщение.
 */
final class PushSender
{
    public const BATCH = 20;

    public static function processBatch(int $limit = self::BATCH): int
    {
        if (! WebPush::configured()) {
            return 0;
        }

        return DB::transaction(function () use ($limit) {
            $rows = DB::select(
                "SELECT id, user_id, type, title, body, link, attempts FROM notification_deliveries
                 WHERE channel = 'PUSH' AND status = 'PENDING' AND next_attempt_at <= UTC_TIMESTAMP(3)
                 ORDER BY created_at LIMIT ".$limit.' '.DbSupport::lockRows(),
            );
            foreach ($rows as $d) {
                self::deliver($d);
            }

            return count($rows);
        });
    }

    private static function deliver(object $d): void
    {
        $q = DB::table('notification_deliveries')->where('id', $d->id);
        $subs = PushSubscription::where('user_id', $d->user_id)->get();
        $attempts = $d->attempts + 1;
        if ($subs->isEmpty()) {
            $q->update(['status' => 'FAILED', 'attempts' => $attempts, 'last_error' => 'No push subscriptions']);

            return;
        }
        $payload = self::payload($d);
        $sent = 0;
        $transient = [];
        $errors = [];
        foreach ($subs as $sub) {
            $result = self::send($sub, $payload);
            if ($result === true) {
                $sent++;
                $sub->forceFill(['last_success_at' => now()])->save();
            } elseif ($result === 'gone') {
                $sub->delete();
            } else {
                [$retry, $message] = $result;
                $errors[] = $message;
                if ($retry) {
                    $transient[] = $message;
                }
            }
        }
        if ($sent > 0) {
            $q->update(['status' => 'SENT', 'sent_at' => now(), 'attempts' => $attempts, 'last_error' => $errors ? mb_substr(implode('; ', $errors), 0, 500) : null]);
        } elseif ($transient && $attempts < TelegramSender::MAX_ATTEMPTS) {
            $q->update(['attempts' => $attempts, 'last_error' => mb_substr(implode('; ', $transient), 0, 500),
                'next_attempt_at' => now()->addSeconds(TelegramSender::backoff($attempts))]);
        } else {
            $q->update(['status' => 'FAILED', 'attempts' => $attempts, 'last_error' => mb_substr(implode('; ', $errors) ?: 'Subscriptions expired', 0, 500)]);
        }
    }

    /** Содержимое уведомления для service worker (sw.js → push). */
    public static function payload(object $d): string
    {
        $link = (string) ($d->link ?? '');
        $data = [
            'title' => mb_substr((string) $d->title, 0, 200),
            'body' => $d->body !== null ? mb_substr((string) $d->body, 0, 600) : '',
            'url' => str_starts_with($link, '/') && ! str_starts_with($link, '//') ? $link : '/notifications',
            'tag' => 'fluggi-'.$d->id,
            'icon' => '/brand/icon-192',
            'badge' => '/brand/icon-192',
        ];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        while (strlen($json) > WebPushCrypto::MAX_PLAINTEXT && mb_strlen($data['body']) > 0) {
            $data['body'] = mb_substr($data['body'], 0, (int) floor(mb_strlen($data['body']) / 2));
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $json;
    }

    /**
     * @return true|'gone'|array{0:bool,1:string} true — доставлено, 'gone' — подписка удалена,
     *                                            [повторять?, текст ошибки]
     */
    public static function send(PushSubscription $sub, string $payload): bool|string|array
    {
        if (! WebPush::endpointAllowed($sub->endpoint)) {
            return 'gone';
        }
        try {
            $body = WebPushCrypto::encrypt($payload, WebPushCrypto::unb64($sub->p256dh), WebPushCrypto::unb64($sub->auth));
            $auth = WebPushCrypto::vapidAuthorization($sub->endpoint, WebPush::subject(), (string) config('fluggi.webpush.public_key'), (string) config('fluggi.webpush.private_key'));
        } catch (Throwable $e) {
            return [false, 'Encrypt: '.$e->getMessage()];
        }
        try {
            $res = Http::timeout(10)->withHeaders([
                'Authorization' => $auth,
                'Content-Encoding' => 'aes128gcm',
                'TTL' => '86400',
                'Urgency' => 'high',
            ])->withBody($body, 'application/octet-stream')->post($sub->endpoint);
        } catch (ConnectionException $e) {
            return [true, 'Network: '.mb_substr($e->getMessage(), 0, 200)];
        }
        $status = $res->status();
        if ($status >= 200 && $status < 300) {
            return true;
        }
        if ($status === 404 || $status === 410) {
            return 'gone';
        }

        return [$status === 429 || $status >= 500, 'HTTP '.$status.' '.mb_substr((string) $res->body(), 0, 200)];
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
