<?php

namespace App\Models;

use App\Casts\SpecialtySet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Команда проекта (ТЗ §21). РОП и менеджер проекта хранятся в самом проекте.
 * У участника может быть несколько позиций (roles: фотограф + видеограф + мобилограф),
 * role — основная (первая выбранная) позиция, всегда входит в roles.
 */
class ProjectMember extends Model
{
    protected $table = 'project_members';

    public const CREATED_AT = null;

    protected $casts = [
        'roles' => SpecialtySet::class,
        'workload_pct' => 'integer',
        'deadline' => 'date',
        'assigned_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // role и roles согласованы при любой записи: прежний код, который пишет только role, тоже работает
        static::saving(function (self $m) {
            if ($m->isDirty('roles')) {
                $roles = $m->roles;
                if (! in_array($m->role, $roles, true)) {
                    $m->role = $roles[0] ?? null;
                }
            } elseif ($m->isDirty('role') || (! $m->exists && $m->role && ! $m->roles)) {
                $m->roles = $m->role ? [$m->role] : [];
            }
        });
    }

    /** @return list<string> позиции участника: основная первой */
    public function positions(): array
    {
        $roles = $this->roles ?: ($this->role ? [$this->role] : []);
        if ($this->role && in_array($this->role, $roles, true)) {
            $roles = array_values(array_unique([$this->role, ...$roles]));
        }

        return $roles;
    }

    public function hasPosition(string $specialty): bool
    {
        return in_array($specialty, $this->positions(), true);
    }

    /** Участники, у которых есть позиция $specialty. */
    public function scopeWithPosition(Builder $q, string $specialty): Builder
    {
        return $q->where(fn ($w) => $w->whereRaw('FIND_IN_SET(?, `project_members`.`roles`) > 0', [$specialty])->orWhere('project_members.role', $specialty));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id')->withTrashed(); // удалённая запись видна в истории связанных
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }
}
