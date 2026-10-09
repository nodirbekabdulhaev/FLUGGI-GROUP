<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Повторный контакт после завершения проекта (ТЗ §38).
 */
class FollowUp extends Model
{
    protected $table = 'follow_ups';

    protected $casts = [
        'due_date' => 'date',
        'notified_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    public function resultDeal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'result_deal_id');
    }
}
