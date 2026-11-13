<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Таблица partner_payouts: заявки на выплату доли партнёра по проекту (REQUESTED → APPROVED | REJECTED).
 * Записи не удаляются: отказ — с причиной.
 */
class PartnerPayout extends Model
{
    protected $table = 'partner_payouts';

    protected $casts = [
        'amount_uzs' => 'decimal:2',
        'decided_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id')->withTrashed();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
