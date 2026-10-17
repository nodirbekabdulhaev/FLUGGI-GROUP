<?php

namespace App\Auth;

use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Сессии входа (таблица sessions): в cookie-сессии Laravel хранится случайный токен,
 * в базе — только его SHA-256. Так сотрудник видит свои устройства и может завершить любое,
 * а блокировка пользователя или смена пароля сразу закрывает его сессии.
 */
final class Sessions
{
    /** Как часто продлевать скользящую сессию (не на каждый запрос), секунд. */
    private const TOUCH_INTERVAL = 300;

    public static function ttlDays(): int
    {
        return max(1, (int) config('fluggi.session_ttl_days', 7));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array{token:string, session:Session} */
    public static function create(User $user, ?string $ip, ?string $userAgent): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session = Session::create([
            'user_id' => $user->id,
            'token_hash' => self::hash($token),
            'ip' => $ip,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
            'last_seen_at' => now(),
            'expires_at' => now()->addDays(self::ttlDays()),
        ]);

        return ['token' => $token, 'session' => $session];
    }

    /** Проверяет токен и собирает права пользователя; null — сессия недействительна. */
    public static function resolve(string $token): ?Access
    {
        $session = Session::where('token_hash', self::hash($token))->first();
        $now = now();
        if (! $session || $session->revoked_at || $session->expires_at <= $now) {
            return null;
        }
        $user = User::with(['role', 'team'])->find($session->user_id);
        if (! $user || $user->status !== 'ACTIVE') {
            return null;
        }
        if ($now->diffInSeconds($session->last_seen_at, true) > self::TOUCH_INTERVAL) {
            $session->update(['last_seen_at' => $now, 'expires_at' => $now->copy()->addDays(self::ttlDays())]);
        }

        $permissions = DB::table('role_permissions as rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $user->role_id)
            ->pluck('rp.scope', 'p.code')
            ->all();
        $team = $user->team && ! $user->team->deleted_at ? $user->team : null;

        return new Access(
            user: $user,
            sessionId: $session->id,
            roleCode: $user->role->code,
            roleName: $user->role->name,
            permissions: $permissions,
            teamId: $team?->id,
            headedTeamIds: DB::table('teams')->where('head_id', $user->id)->whereNull('deleted_at')->pluck('id')->all(),
            directionIds: DB::table('user_directions')->where('user_id', $user->id)->pluck('direction_id')->all(),
        );
    }

    public static function revoke(string $sessionId): void
    {
        Session::where('id', $sessionId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public static function revokeAllForUser(string $userId, ?string $exceptSessionId = null): void
    {
        Session::where('user_id', $userId)->whereNull('revoked_at')
            ->when($exceptSessionId, fn ($q) => $q->where('id', '<>', $exceptSessionId))
            ->update(['revoked_at' => now()]);
    }
}
