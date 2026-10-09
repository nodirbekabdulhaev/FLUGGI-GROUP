<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Каталог услуг (ТЗ §61).
 */
class Service extends Model
{
    protected $table = 'services';

    protected $casts = [
        'base_price' => 'decimal:2',
        'min_price' => 'decimal:2',
        'is_active' => 'boolean',
        'sort' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class, 'direction_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'service_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'service_id');
    }

    public function proposalItems(): HasMany
    {
        return $this->hasMany(ProposalItem::class, 'service_id');
    }

    public function projectTemplates(): HasMany
    {
        return $this->hasMany(ProjectTemplate::class, 'service_id');
    }

    public function tariffs(): HasMany
    {
        return $this->hasMany(Tariff::class, 'service_id');
    }
}
