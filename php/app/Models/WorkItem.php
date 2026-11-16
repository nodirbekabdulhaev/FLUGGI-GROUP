<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Единица работы исполнителя (рилс, обложка, карусель, сторис…) с базовой ставкой.
 */
class WorkItem extends Model
{
    protected $table = 'work_items';

    protected $casts = [
        'default_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'sort' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function rates(): HasMany
    {
        return $this->hasMany(EmployeeRate::class, 'work_item_id');
    }

    public function tariffItems(): HasMany
    {
        return $this->hasMany(TariffItem::class, 'work_item_id');
    }

    public function costLines(): HasMany
    {
        return $this->hasMany(ProjectCostLine::class, 'work_item_id');
    }
}
