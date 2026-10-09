<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Тариф услуги: эконом / стандарт / премиум — цена продажи и состав работ.
 */
class Tariff extends Model
{
    protected $table = 'tariffs';

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'sort' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TariffItem::class, 'tariff_id');
    }

    public function proposalItems(): HasMany
    {
        return $this->hasMany(ProposalItem::class, 'tariff_id');
    }

    public function costLines(): HasMany
    {
        return $this->hasMany(ProjectCostLine::class, 'tariff_id');
    }
}
