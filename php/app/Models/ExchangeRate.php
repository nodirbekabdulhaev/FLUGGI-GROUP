<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Курс валюты к UZS на дату. Суммы фиксируют курс в момент сохранения.
 */
class ExchangeRate extends Model
{
    protected $table = 'exchange_rates';

    public const UPDATED_AT = null;

    protected $casts = [
        'date' => 'date',
        'rate_to_uzs' => 'decimal:6',
        'created_at' => 'datetime',
    ];

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_id');
    }
}
