<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Договор (ТЗ §17). Не удаляется — только отменяется.
 */
class Contract extends Model
{
    protected $table = 'contracts';

    protected $casts = [
        'number' => 'integer',
        'contract_date' => 'date',
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'amount_uzs' => 'decimal:2',
        'signed_at' => 'datetime',
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

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class, 'proposal_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'contract_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(StoredFile::class, 'contract_id');
    }
}
