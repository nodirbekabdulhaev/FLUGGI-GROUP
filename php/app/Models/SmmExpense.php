<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Прочий расход SMM (реквизит, студия, реклама…) за месяц; при желании — с операцией в Бухгалтерии. */
class SmmExpense extends Model
{
    use SoftDeletes;

    protected $table = 'smm_expenses';

    protected $casts = [
        'date' => 'date',
        'amount_uzs' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id')->withTrashed();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(AccTransaction::class, 'acc_transaction_id')->withTrashed();
    }
}
