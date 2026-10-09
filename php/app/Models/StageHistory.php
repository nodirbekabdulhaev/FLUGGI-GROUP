<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * История переходов по воронке (ТЗ §7). Append-only.
 */
class StageHistory extends Model
{
    protected $table = 'stage_history';

    public const UPDATED_AT = null;

    protected $casts = [
        'duration_sec' => 'integer',
        'created_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(DealStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(DealStage::class, 'to_stage_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }
}
