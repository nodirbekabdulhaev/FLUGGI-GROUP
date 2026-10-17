<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Правила комиссий (ТЗ §33–34) — данные, а не код.
 */
class CommissionRule extends Model
{
    protected $table = 'commission_rules';

    protected $casts = [
        'value' => 'decimal:4',
        'conditions' => 'array',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'rule_id');
    }
}
