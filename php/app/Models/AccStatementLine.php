<?php

namespace App\Models;

/** Бухгалтерия: строка баланса или финрезультатов, заданная вручную или загруженная из сданной формы. */
class AccStatementLine extends Model
{
    protected $table = 'acc_statement_lines';

    protected $casts = [
        'year' => 'integer',
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
