<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Зарплата за месяц (ТЗ §32). Комиссия хранится отдельно и только суммируется сюда.
 */
class PayrollEntry extends Model
{
    protected $table = 'payroll_entries';

    protected $casts = [
        'base_salary' => 'decimal:2',
        'piece_rate' => 'decimal:2',
        'kpi_bonus' => 'decimal:2',
        'commission' => 'decimal:2',
        'other_bonus' => 'decimal:2',
        'penalty' => 'decimal:2',
        'final_salary' => 'decimal:2',
        'kpi_pct' => 'decimal:2',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }
}
