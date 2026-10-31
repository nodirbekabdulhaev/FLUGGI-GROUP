<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Доставка во внешний канал (Telegram) с повторами и журналом (ТЗ §53).
 */
class NotificationDelivery extends Model
{
    protected $table = 'notification_deliveries';

    public const UPDATED_AT = null;

    protected $casts = [
        'attempts' => 'integer',
        'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
