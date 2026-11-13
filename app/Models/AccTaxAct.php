<?php

namespace App\Models;

/** Бухгалтерия: строка акта сверки с налоговой (налог за год: сальдо на начало и конец, начислено, оплачено). */
class AccTaxAct extends Model
{
    protected $table = 'acc_tax_acts';

    protected $casts = [
        'year' => 'integer',
        'as_of' => 'date',
        'year_end' => 'boolean',
        'opening_debt' => 'decimal:2',
        'opening_overpay' => 'decimal:2',
        'closing_debt' => 'decimal:2',
        'closing_overpay' => 'decimal:2',
        'closing_penalty' => 'decimal:2',
        'accrued' => 'decimal:2',
        'accrued_penalty' => 'decimal:2',
        'paid' => 'decimal:2',
        'refunded' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
