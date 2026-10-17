<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Таблица proposal_items.
 */
class ProposalItem extends Model
{
    protected $table = 'proposal_items';

    public $timestamps = false;

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'discount_pct' => 'decimal:2',
        'total' => 'decimal:2',
        'sort' => 'integer',
    ];

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class, 'proposal_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class, 'tariff_id');
    }
}
