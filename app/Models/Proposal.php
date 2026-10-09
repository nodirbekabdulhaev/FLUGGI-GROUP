<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Коммерческое предложение (ТЗ §15). Каждое сохранение создаёт версию (§16).
 */
class Proposal extends Model
{
    protected $table = 'proposals';

    protected $casts = [
        'number' => 'integer',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'total_uzs' => 'decimal:2',
        'valid_until' => 'date',
        'current_version' => 'integer',
        'approved_at' => 'datetime',
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
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

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProposalItem::class, 'proposal_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProposalVersion::class, 'proposal_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'proposal_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(StoredFile::class, 'proposal_id');
    }
}
