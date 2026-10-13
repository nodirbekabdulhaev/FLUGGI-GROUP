<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Бухгалтерия: операция по счёту (приход/расход). Удаление — мягкое, с причиной. */
class AccTransaction extends Model
{
    use SoftDeletes;

    protected $table = 'acc_transactions';

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccAccount::class, 'account_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(AccPerson::class, 'person_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
