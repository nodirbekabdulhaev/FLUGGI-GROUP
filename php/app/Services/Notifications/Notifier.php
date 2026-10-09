<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationSetting;
use App\Models\Team;
use App\Models\User;
use App\Support\Lang;
use Illuminate\Support\Str;

/**
 * Уведомление пользователям (ТЗ §14, §53): in-app и Telegram с учётом личных настроек.
 * Дубли получателей и «сам себе» отсекаются. В Telegram — только если бот настроен
 * и сотрудник подключил чат; отправку делает очередь notification_deliveries (cron, с повторами).
 *
 * Текст строится на языке получателя: $content — fn (string $locale): array{0: string, 1: ?string}
 * (заголовок и текст) или готовая пара [заголовок, текст].
 */
final class Notifier
{
    /**
     * @param  array<int,?string>  $userIds
     * @param  callable(string):array{0:string,1?:?string}|array{0:string,1?:?string}  $content
     */
    public static function notify(array $userIds, string $type, callable|array $content, ?string $link = null, ?string $exceptUserId = null, bool $inApp = true): void
    {
        $ids = array_values(array_unique(array_filter($userIds, fn ($id) => $id && $id !== $exceptUserId)));
        if (! $ids) {
            return;
        }
        $group = NotificationCatalog::group($type);
        $off = NotificationSetting::whereIn('user_id', $ids)->where('event_type', $group)->where('enabled', false)
            ->get(['user_id', 'channel'])
            ->map(fn ($r) => $r->user_id.':'.$r->channel)->flip();
        $users = User::whereIn('id', $ids)->where('status', 'ACTIVE')->get(['id', 'telegram_chat_id', 'locale']);
        $telegramOn = (bool) config('fluggi.telegram.token');

        $texts = [];
        $render = function (string $locale) use (&$texts, $content): array {
            if (! isset($texts[$locale])) {
                $previous = app()->getLocale();
                app()->setLocale($locale);
                try {
                    $pair = is_callable($content) ? $content($locale) : $content;
                } finally {
                    app()->setLocale($previous);
                }
                $texts[$locale] = [mb_substr((string) $pair[0], 0, 1000), isset($pair[1]) && $pair[1] !== '' ? (string) $pair[1] : null];
            }

            return $texts[$locale];
        };

        $now = now();
        $notes = [];
        $deliveries = [];
        foreach ($users as $u) {
            $locale = in_array($u->locale, Lang::LOCALES, true) ? $u->locale : Lang::FALLBACK;
            [$title, $body] = $render($locale);
            if ($inApp && ! $off->has($u->id.':IN_APP')) {
                $notes[] = ['id' => (string) Str::uuid(), 'user_id' => $u->id, 'type' => $type, 'title' => $title,
                    'body' => $body, 'link' => $link, 'created_at' => $now->format('Y-m-d H:i:s.v')];
            }
            if ($telegramOn && $u->telegram_chat_id && ! $off->has($u->id.':TELEGRAM')) {
                $deliveries[] = ['id' => (string) Str::uuid(), 'user_id' => $u->id, 'channel' => 'TELEGRAM', 'type' => $type,
                    'title' => $title, 'body' => $body, 'link' => $link, 'status' => 'PENDING', 'attempts' => 0,
                    'next_attempt_at' => $now->format('Y-m-d H:i:s.v'), 'created_at' => $now->format('Y-m-d H:i:s.v')];
            }
        }
        if ($notes) {
            Notification::insert($notes);
        }
        if ($deliveries) {
            NotificationDelivery::insert($deliveries);
        }
    }

    /** Активные CEO. */
    public static function ceoIds(): array
    {
        return User::where('status', 'ACTIVE')->whereHas('role', fn ($q) => $q->where('code', 'CEO'))->pluck('id')->all();
    }

    public static function teamHead(?string $teamId): ?string
    {
        return $teamId ? Team::withTrashed()->whereKey($teamId)->value('head_id') : null;
    }
}
