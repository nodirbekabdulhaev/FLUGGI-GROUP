<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Таблица tariff_items.
 */
class TariffItem extends Model
{
    protected $table = 'tariff_items';

    public $timestamps = false;

    protected $casts = [
        'quantity' => 'decimal:2',
        'amount' => 'decimal:2',
        'sort' => 'integer',
    ];

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class, 'tariff_id');
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'work_item_id');
    }

    public function costLines(): HasMany
    {
        return $this->hasMany(ProjectCostLine::class, 'tariff_item_id');
    }
}
