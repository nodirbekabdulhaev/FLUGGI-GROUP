<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Бухгалтерия: строка сданного отчёта в налоговую (человек за месяц, суммы нарастающим итогом за год). */
class AccTaxFiling extends Model
{
    protected $table = 'acc_tax_filings';

    protected $casts = [
        'status' => 'integer',
        'work_start' => 'date',
        'income_ytd' => 'decimal:2',
        'income_month' => 'decimal:2',
        'ndfl_ytd' => 'decimal:2',
        'ndfl_month' => 'decimal:2',
        'benefits' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(AccPerson::class, 'person_id');
    }
}
