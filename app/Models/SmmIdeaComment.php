<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Комментарий к идее ролика. */
class SmmIdeaComment extends Model
{
    protected $table = 'smm_idea_comments';

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function idea(): BelongsTo
    {
        return $this->belongsTo(SmmIdea::class, 'idea_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
