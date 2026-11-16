<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Аванс сотруднику: удерживается из зарплаты месяца period (payroll_entries.advances).
 * Не удаляется — только отмена с причиной (cancelled_at).
 */
class EmployeeAdvance extends Model
{
    protected $table = 'employee_advances';

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'date',
        'cancelled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id')->withTrashed();
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id')->withTrashed();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(AccTransaction::class, 'accounting_transaction_id')->withTrashed();
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('cancelled_at');
    }
}
