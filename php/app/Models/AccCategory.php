<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Бухгалтерия: своя статья прихода или расхода (например, «Аренда склада»).
 * base — базовая категория, как которая статья учитывается в налогах и отчётах.
 */
class AccCategory extends Model
{
    protected $table = 'acc_categories';

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(AccTransaction::class, 'category_id');
    }

    /** «Аренда — Аренда склада». */
    public function label(): string
    {
        return t('accounting.category.'.$this->base).' — '.$this->name;
    }
}
