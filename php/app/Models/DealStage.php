<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Этапы воронки (ТЗ §7). code фиксирован и используется бизнес-правилами;
 * название, порядок, вероятность и цвет редактируются.
 */
class DealStage extends Model
{
    protected $table = 'deal_stages';

    protected $casts = [
        'sort' => 'integer',
        'probability' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'stage_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'stage_id');
    }

    public function fromHist(): HasMany
    {
        return $this->hasMany(StageHistory::class, 'from_stage_id');
    }

    public function toHist(): HasMany
    {
        return $this->hasMany(StageHistory::class, 'to_stage_id');
    }
}
