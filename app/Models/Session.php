<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Таблица sessions.
 */
class Session extends Model
{
    protected $table = 'sessions';

    public const UPDATED_AT = null;

    protected $casts = [
        'created_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
