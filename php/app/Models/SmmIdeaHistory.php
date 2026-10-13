<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** История идеи ролика — только добавление: кто, когда и что изменил (старое → новое). */
class SmmIdeaHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'smm_idea_history';

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    public function idea(): BelongsTo
    {
        return $this->belongsTo(SmmIdea::class, 'idea_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
