<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * In-app уведомления. Telegram-доставка — Phase 7.
 */
class Notification extends Model
{
    protected $table = 'notifications';

    public const UPDATED_AT = null;

    protected $casts = [
        'read_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
