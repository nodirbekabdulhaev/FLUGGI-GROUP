<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Расход (ТЗ §26): проектный уменьшает прибыль проекта, расход компании — операционную прибыль.
 */
class Expense extends Model
{
    use SoftDeletes;

    protected $table = 'expenses';

    protected $casts = [
        'number' => 'integer',
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'amount_uzs' => 'decimal:2',
        'expense_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function categoryRef(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category');
    }

    public function costLine(): HasOne
    {
        return $this->hasOne(ProjectCostLine::class, 'expense_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function payee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payee_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
