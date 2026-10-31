<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Подписки браузеров на Web Push. Подписываются все сотрудники (разрешение браузера запрашивается
 * в интерфейсе); выключить push в настройках CRM нельзя — только отозвать разрешение в браузере.
 */
final class WebPush
{
    public static function configured(): bool
    {
        return (bool) (config('fluggi.webpush.public_key') && config('fluggi.webpush.private_key'));
    }

    public static function publicKey(): ?string
    {
        return config('fluggi.webpush.public_key') ?: null;
    }

    /** Контакт VAPID: mailto:/https: из .env, иначе адрес CRM. */
    public static function subject(): string
    {
        $s = (string) config('fluggi.webpush.subject');

        return $s !== '' ? $s : (rtrim((string) config('app.url'), '/') ?: 'mailto:admin@localhost');
    }

    /** Адрес — https и домен известного push-сервиса браузера (сервер не отправляет запросы на произвольные адреса). */
    public static function endpointAllowed(string $endpoint): bool
    {
        $p = parse_url($endpoint);
        if (($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
            return false;
        }
        $host = strtolower($p['host']);
        foreach ((array) config('fluggi.webpush.hosts') as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Сохранить подписку браузера. Тот же браузер под другим сотрудником — подписка переходит к нему
     * (уведомления прежнего сотрудника на это устройство больше не приходят).
     */
    public static function subscribe(User $user, string $endpoint, string $p256dh, string $auth, ?string $userAgent): PushSubscription
    {
        $errors = [];
        if (! self::endpointAllowed($endpoint)) {
            $errors['endpoint'] = t('notifications.push.errors.endpoint');
        }
        try {
            $key = WebPushCrypto::unb64($p256dh);
            WebPushCrypto::publicKey($key);
        } catch (\Throwable) {
            $errors['keys.p256dh'] = t('notifications.push.errors.keys');
        }
        try {
            if (strlen(WebPushCrypto::unb64($auth)) !== 16) {
                $errors['keys.auth'] = t('notifications.push.errors.keys');
            }
        } catch (\Throwable) {
            $errors['keys.auth'] = t('notifications.push.errors.keys');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $hash = hash('sha256', $endpoint);

        return DB::transaction(function () use ($user, $endpoint, $hash, $p256dh, $auth, $userAgent) {
            $sub = PushSubscription::where('endpoint_hash', $hash)->lockForUpdate()->first();
            $data = ['user_id' => $user->id, 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null];
            if ($sub) {
                $previous = $sub->user_id;
                $sub->update($data);
                if ($previous !== $user->id) {
                    Audit::log('push.subscribed', 'push_subscription', $sub->id, ['user_id' => ['old' => $previous, 'new' => $user->id]], $user->id);
                }
            } else {
                $sub = PushSubscription::create(['id' => (string) Str::uuid(), 'endpoint_hash' => $hash] + $data);
                Audit::log('push.subscribed', 'push_subscription', $sub->id, null, $user->id);
            }

            return $sub;
        });
    }

    /** Отписка — только своей подписки (чужая не удаляется, ответ тот же). */
    public static function unsubscribe(User $user, string $endpoint): bool
    {
        return DB::transaction(function () use ($user, $endpoint) {
            $sub = PushSubscription::where('endpoint_hash', hash('sha256', $endpoint))->where('user_id', $user->id)->first();
            if (! $sub) {
                return false;
            }
            $sub->delete();
            Audit::log('push.unsubscribed', 'push_subscription', $sub->id, null, $user->id);

            return true;
        });
    }

    /** У кого из сотрудников есть подписки. */
    public static function subscribedUserIds(array $userIds): array
    {
        if (! $userIds || ! self::configured()) {
            return [];
        }

        return PushSubscription::whereIn('user_id', $userIds)->distinct()->pluck('user_id')->all();
    }
}
