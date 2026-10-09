<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Таблица loss_reasons.
 */
class LossReason extends Model
{
    protected $table = 'loss_reasons';

    protected $casts = [
        'requires_comment' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'loss_reason_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'loss_reason_id');
    }
}
