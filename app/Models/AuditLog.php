<?php

namespace App\Models;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    /** Switched off only for bulk demo seeding. */
    public static bool $enabled = true;

    public $timestamps = false;

    protected $guarded = ['id'];
    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function scopeForTenant($q)
    {
        return $q->where('organization_id', Tenant::id());
    }

    public static function record(string $event, ?Model $model, array $old = [], array $new = [], ?string $label = null): void
    {
        $user = auth()->user();
        $req = app()->runningInConsole() ? null : request();

        static::create([
            'organization_id' => $model?->organization_id ?? Tenant::id() ?? $user?->organization_id,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'event' => $event,
            'auditable_type' => $model ? class_basename($model) : $label,
            'auditable_id' => $model?->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip' => $req?->ip(),
            'user_agent' => $req ? mb_substr((string) $req->userAgent(), 0, 255) : null,
            'created_at' => now(),
        ]);
    }
}
