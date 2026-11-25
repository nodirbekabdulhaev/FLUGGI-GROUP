<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Неизменяемый снимок КП на момент сохранения.
 */
class ProposalVersion extends Model
{
    protected $table = 'proposal_versions';

    public const UPDATED_AT = null;

    protected $casts = [
        'version' => 'integer',
        'snapshot' => 'array',
        'total' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class, 'proposal_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
