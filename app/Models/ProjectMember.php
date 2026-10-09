<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Команда проекта (ТЗ §21). РОП и менеджер проекта хранятся в самом проекте.
 */
class ProjectMember extends Model
{
    protected $table = 'project_members';

    public const CREATED_AT = null;

    protected $casts = [
        'workload_pct' => 'integer',
        'deadline' => 'date',
        'assigned_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
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
