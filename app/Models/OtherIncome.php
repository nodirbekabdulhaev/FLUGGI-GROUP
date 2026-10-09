<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Прочие поступления (не оплаты клиентов): возврат от поставщика, партнёрские, проценты банка…
 */
class OtherIncome extends Model
{
    use SoftDeletes;

    protected $table = 'other_incomes';

    protected $casts = [
        'number' => 'integer',
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'amount_uzs' => 'decimal:2',
        'income_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function categoryRef(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
