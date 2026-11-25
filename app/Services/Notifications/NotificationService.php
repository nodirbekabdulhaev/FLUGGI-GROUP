<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\NotificationSetting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Уведомления — личные: каждый видит только свои. */
final class NotificationService
{
    public static function list(string $userId, int $perPage = 25): LengthAwarePaginator
    {
        return Notification::where('user_id', $userId)->orderByDesc('created_at')->paginate($perPage)->withQueryString();
    }

    public static function unread(string $userId): int
    {
        return Notification::where('user_id', $userId)->whereNull('read_at')->count();
    }

    /**
     * Для колокольчика: число непрочитанных и самое новое из них (заголовок и ссылка для всплывающего окна со звуком).
     *
     * @return array{count:int, latest:?array{id:string,title:string,body:?string,url:string,at:string}}
     */
    public static function unreadSummary(string $userId): array
    {
        $count = self::unread($userId);
        $latest = $count > 0 ? Notification::where('user_id', $userId)->whereNull('read_at')->orderByDesc('created_at')->orderByDesc('id')->first() : null;

        return [
            'count' => $count,
            'latest' => $latest ? [
                'id' => $latest->id,
                'title' => (string) $latest->title,
                'body' => $latest->body !== null ? mb_substr((string) $latest->body, 0, 300) : null,
                'url' => '/notifications/'.$latest->id.'/open',
                'at' => $latest->created_at?->toIso8601ZuluString('millisecond'),
            ] : null,
        ];
    }

    /** Отметить прочитанным одно уведомление или все. */
    public static function markRead(string $userId, ?string $id = null): int
    {
        return Notification::where('user_id', $userId)->whereNull('read_at')
            ->when($id, fn ($q) => $q->whereKey($id))
            ->update(['read_at' => now()]);
    }

    /**
     * Личные настройки: типы для роли пользователя и состояние каналов.
     *
     * @return list<array{type:string,label:string,inApp:bool,telegram:bool}>
     */
    public static function settings(string $userId, string $roleCode): array
    {
        $off = NotificationSetting::where('user_id', $userId)->where('enabled', false)->get()
            ->map(fn ($r) => $r->event_type.':'.$r->channel)->flip();

        return array_map(fn (string $type) => [
            'type' => $type,
            'label' => NotificationCatalog::label($type),
            // В CRM (со звуком) и push — всегда включены
            'inApp' => true,
            'telegram' => ! $off->has($type.':TELEGRAM'),
        ], NotificationCatalog::forRole($roleCode));
    }

    /** @param  list<array{eventType:string,channel:string,enabled:bool}>  $items */
    public static function saveSettings(string $userId, array $items): void
    {
        DB::transaction(function () use ($userId, $items) {
            foreach ($items as $i) {
                // Уведомления в CRM не отключаются — сохраняются только настройки Telegram
                if (! array_key_exists($i['eventType'], NotificationCatalog::EVENTS) || $i['channel'] !== 'TELEGRAM') {
                    continue;
                }
                $key = ['user_id' => $userId, 'event_type' => $i['eventType'], 'channel' => $i['channel']];
                if (NotificationSetting::where($key)->exists()) {
                    NotificationSetting::where($key)->update(['enabled' => (bool) $i['enabled']]);
                } else {
                    NotificationSetting::insert($key + ['enabled' => (bool) $i['enabled']]);
                }
            }
        });
    }
}
