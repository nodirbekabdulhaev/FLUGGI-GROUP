<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Бизнес-таймлайн (ТЗ §12). Append-only: изменение и удаление запрещены триггером.
 */
class Activity extends Model
{
    protected $table = 'activities';

    public const UPDATED_AT = null;

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id')->withTrashed(); // удалённая запись видна в истории связанных
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id')->withTrashed(); // удалённая запись видна в истории связанных
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id')->withTrashed(); // удалённая запись видна в истории связанных
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id')->withTrashed(); // удалённая запись видна в истории связанных
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }
}
