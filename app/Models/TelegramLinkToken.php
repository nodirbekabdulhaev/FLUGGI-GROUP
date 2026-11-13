<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одноразовый код привязки Telegram (ТЗ §53): /start <код> в боте связывает чат с аккаунтом.
 */
class TelegramLinkToken extends Model
{
    protected $table = 'telegram_link_tokens';

    public const UPDATED_AT = null;

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
