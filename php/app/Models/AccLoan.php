<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Бухгалтерия: кредит. График платежей — AccLoanPayment. */
class AccLoan extends Model
{
    use SoftDeletes;

    protected $table = 'acc_loans';

    protected $casts = [
        'principal' => 'decimal:2',
        'rate' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'remind_days' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(AccLoanPayment::class, 'loan_id')->orderBy('due_date');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
