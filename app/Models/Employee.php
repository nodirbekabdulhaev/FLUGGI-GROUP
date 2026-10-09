<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-данные сотрудника. Отделены от users, чтобы не попадать в CRM-выборки.
 */
class Employee extends Model
{
    protected $table = 'employees';

    protected $casts = [
        'base_salary' => 'decimal:2',
        'kpi_bonus_target' => 'decimal:2',
        'hired_at' => 'date',
        'fired_at' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class, 'schedule_id');
    }
}
