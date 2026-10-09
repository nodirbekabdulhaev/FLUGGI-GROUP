<?php

namespace App\Support;

use App\Models\AuditLog;
use BackedEnum;
use DateTimeInterface;

/**
 * Журнал аудита (ТЗ §45). Пишется в той же транзакции, что и изменение. Таблица append-only:
 * изменение и удаление записей запрещены (в приложении и триггерами MySQL).
 */
final class Audit
{
    /** @param  array<string,array{old:mixed,new:mixed}>|null  $changes */
    public static function log(string $action, string $entityType, ?string $entityId = null, ?array $changes = null, ?string $actorId = null): void
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $access = access(false);
        AuditLog::create([
            'actor_id' => $actorId ?? $access?->id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'changes' => $changes,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            'session_id' => $access?->sessionId,
        ]);
    }

    /**
     * Изменившиеся поля: [поле => [old, new]] или null, если ничего не изменилось.
     *
     * @param  array<string,mixed>|object  $before
     * @param  array<string,mixed>  $after
     */
    public static function diff(array|object $before, array $after, array $fields): ?array
    {
        $before = is_object($before) ? (method_exists($before, 'getAttributes') ? $before->getAttributes() : (array) $before) : $before;
        $changes = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $after)) {
                continue;
            }
            $old = self::normalize($before[$field] ?? null);
            $new = self::normalize($after[$field]);
            if (json_encode($old) !== json_encode($new)) {
                $changes[$field] = ['old' => $old, 'new' => $new];
            }
        }

        return $changes ?: null;
    }

    private static function normalize(mixed $v): mixed
    {
        return match (true) {
            $v instanceof DateTimeInterface => $v->format('Y-m-d\TH:i:s.v\Z'),
            $v instanceof BackedEnum => $v->value,
            is_object($v) && method_exists($v, '__toString') => (string) $v,
            is_float($v) || is_int($v) => (string) $v,
            default => $v,
        };
    }
}
