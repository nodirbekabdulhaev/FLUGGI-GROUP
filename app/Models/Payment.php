<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Оплата (ТЗ §18). Не удаляется: ошибочная — CANCELLED, возврат — отдельная запись REFUND.
 */
class Payment extends Model
{
    protected $table = 'payments';

    protected $casts = [
        'number' => 'integer',
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'amount_uzs' => 'decimal:2',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'refund_of_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Payment::class, 'refund_of_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'payment_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(StoredFile::class, 'payment_id');
    }
}
