<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Редактируемый справочник категорий (CEO). Код не меняется — на него ссылаются записи.
 */
class FinanceCategory extends Model
{
    protected $table = 'finance_categories';

    public const UPDATED_AT = null;

    protected $casts = [
        'is_overhead' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
        'created_at' => 'datetime',
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category');
    }

    public function otherIncomes(): HasMany
    {
        return $this->hasMany(OtherIncome::class, 'category');
    }
}
