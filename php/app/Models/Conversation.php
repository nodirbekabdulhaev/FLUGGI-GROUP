<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Таблица conversations.
 */
class Conversation extends Model
{
    protected $table = 'conversations';

    public const UPDATED_AT = null;

    protected $casts = [
        'last_message_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(ConversationMember::class, 'conversation_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }
}
