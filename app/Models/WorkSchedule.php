<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Рабочий график (ТЗ §36): для роли или для отдельных сотрудников.
 */
class WorkSchedule extends Model
{
    protected $table = 'work_schedules';

    protected $casts = [
        'work_days' => 'array',
        'grace_minutes' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'schedule_id');
    }
}
