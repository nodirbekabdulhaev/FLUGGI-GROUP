<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Подписка браузера на Web Push (системные уведомления со звуком). */
class PushSubscription extends Model
{
    protected $table = 'push_subscriptions';

    protected $casts = [
        'last_success_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = ['p256dh', 'auth'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
