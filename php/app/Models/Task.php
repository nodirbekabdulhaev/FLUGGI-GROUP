<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Задача проекта (ТЗ §22). Исполнитель назначается всегда (ТЗ §77, Rule 5): задача из шаблона,
 * для роли которой в команде ещё нет исполнителя, назначается на РОП проекта до появления исполнителя.
 */
class Task extends Model
{
    use SoftDeletes;

    protected $table = 'tasks';

    protected $casts = [
        'number' => 'integer',
        'start_date' => 'date',
        'deadline' => 'datetime',
        'progress_pct' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'rework_count' => 'integer',
        'overdue_notified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(TaskStatusHistory::class, 'task_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'task_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'task_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(StoredFile::class, 'task_id');
    }
}
