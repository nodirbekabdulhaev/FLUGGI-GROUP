<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Правило регулярного дела: ежемесячно / ежеквартально / ежегодно в заданный день.
 */
class RecurringTodo extends Model
{
    use SoftDeletes;

    protected $table = 'recurring_todos';

    protected $casts = [
        'day_of_month' => 'integer',
        'month' => 'integer',
        'remind_days_before' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class, 'recurring_id');
    }
}
