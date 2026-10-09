<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Личная ставка сотрудника за единицу работы («договор сдельный» в карточке сотрудника).
 */
class EmployeeRate extends Pivot
{
    protected $table = 'employee_rates';

    protected $primaryKey = null;

    public $incrementing = false;

    public const CREATED_AT = null;

    protected $casts = [
        'rate' => 'decimal:2',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'work_item_id');
    }
}
