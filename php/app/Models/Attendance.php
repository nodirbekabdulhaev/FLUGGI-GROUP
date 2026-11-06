<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Посещаемость (ТЗ §35): одна запись на сотрудника в день.
 * auto_checkout — уход не отмечен, cron закрыл день по концу графика; approval_status — решение CEO
 * (PENDING → APPROVED: день засчитан до конца графика | REJECTED: прогул).
 */
class Attendance extends Model
{
    protected $table = 'attendance';

    protected $casts = [
        'date' => 'date',
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'late_minutes' => 'integer',
        'work_minutes' => 'integer',
        'auto_checkout' => 'boolean',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /** День закрыт автоматически (уход не отмечен) и ещё не утверждён или отклонён — в зарплату не идёт. */
    public function notCounted(): bool
    {
        return $this->auto_checkout && $this->approval_status !== 'APPROVED';
    }
}
