<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Бухгалтерия: человек, оформленный для налоговой (не обязательно сотрудник CRM). */
class AccPerson extends Model
{
    protected $table = 'acc_people';

    protected $casts = [
        'monthly_salary' => 'decimal:2',
        'hired_at' => 'date',
        'fired_at' => 'date',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function payroll(): HasMany
    {
        return $this->hasMany(AccPayroll::class, 'person_id');
    }
}
