<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** Бухгалтерия: банковский счёт, касса (наличные) или карта. crm_methods — способы оплат CRM, попадающие сюда. */
class AccAccount extends Model
{
    protected $table = 'acc_accounts';

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'opening_date' => 'date',
        'crm_methods' => 'array',
        'is_active' => 'boolean',
        'sort' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(AccTransaction::class, 'account_id');
    }
}
