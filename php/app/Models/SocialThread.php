<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Переписка из соцсетей: Директ Instagram или комментарии одного человека.
 */
class SocialThread extends Model
{
    protected $table = 'social_threads';

    public const UPDATED_AT = null;

    protected $casts = [
        'unread' => 'integer',
        'last_message_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SocialMessage::class, 'thread_id');
    }
}
