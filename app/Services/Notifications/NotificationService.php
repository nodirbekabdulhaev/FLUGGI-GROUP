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
            'inApp' => ! $off->has($type.':IN_APP'),
            'telegram' => ! $off->has($type.':TELEGRAM'),
        ], NotificationCatalog::forRole($roleCode));
    }

    /** @param  list<array{eventType:string,channel:string,enabled:bool}>  $items */
    public static function saveSettings(string $userId, array $items): void
    {
        DB::transaction(function () use ($userId, $items) {
            foreach ($items as $i) {
                if (! array_key_exists($i['eventType'], NotificationCatalog::EVENTS)) {
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
