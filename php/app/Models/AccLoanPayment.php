<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Бухгалтерия: платёж по кредиту за месяц (основной долг + проценты). */
class AccLoanPayment extends Model
{
    protected $table = 'acc_loan_payments';

    protected $casts = [
        'due_date' => 'date',
        'principal' => 'decimal:2',
        'interest' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_at' => 'date',
        'paid_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(AccLoan::class, 'loan_id');
    }
}
