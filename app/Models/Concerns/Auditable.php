<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;

trait Auditable
{
    protected static array $auditIgnore = ['updated_at', 'created_at', 'remember_token', 'deleted_at'];
    protected static array $auditMask = ['password'];

    public static function bootAuditable(): void
    {
        static::created(fn ($m) => $m->writeAudit('created', [], $m->getAttributes()));

        static::updated(function ($m) {
            $changes = collect($m->getChanges())->except(static::$auditIgnore)->all();
            if (! $changes) {
                return;
            }
            $old = array_intersect_key($m->getOriginal(), $changes);
            $m->writeAudit('updated', $old, $changes);
        });

        static::deleted(fn ($m) => $m->writeAudit(
            method_exists($m, 'isForceDeleting') && $m->isForceDeleting() ? 'force_deleted' : 'deleted',
            $m->getOriginal(),
            []
        ));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn ($m) => $m->writeAudit('restored', [], []));
        }
    }

    public function writeAudit(string $event, array $old, array $new): void
    {
        if (! AuditLog::$enabled) {
            return;
        }

        $mask = fn (array $a) => collect($a)->except(static::$auditIgnore)
            ->map(fn ($v, $k) => in_array($k, static::$auditMask, true) ? '***' : $v)->all();

        AuditLog::record($event, $this, $mask($old), $mask($new));
    }
}
