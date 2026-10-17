<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Бухгалтерия: начисление зарплаты для налоговой за месяц (НДФЛ, ИНПС). После утверждения не меняется. */
class AccPayroll extends Model
{
    protected $table = 'acc_payroll';

    protected $casts = [
        'gross' => 'decimal:2',
        'ndfl_rate' => 'decimal:3',
        'ndfl' => 'decimal:2',
        'inps_rate' => 'decimal:3',
        'inps' => 'decimal:2',
        'net' => 'decimal:2',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(AccPerson::class, 'person_id');
    }
}
