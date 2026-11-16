<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Бухгалтерия: оклад человека, действующий с месяца effective_from (YYYY-MM) и далее, пока не изменён. */
class AccPersonSalary extends Model
{
    protected $table = 'acc_person_salaries';

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(AccPerson::class, 'person_id');
    }
}
