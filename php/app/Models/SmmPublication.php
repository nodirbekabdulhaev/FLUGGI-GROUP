<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Автопубликация идеи ролика в Instagram: DRAFT → SCHEDULED → PUBLISHING → PUBLISHED / FAILED.
 * Файл — storage/app/smm-media (media_path); после публикации удаляется (file_deleted_at), метаданные остаются.
 */
class SmmPublication extends Model
{
    protected $table = 'smm_publications';

    protected $casts = [
        'share_to_feed' => 'boolean',
        'thumb_offset_ms' => 'integer',
        'media_size' => 'integer',
        'cover_size' => 'integer',
        'attempts' => 'integer',
        'scheduled_at' => 'datetime',
        'file_deleted_at' => 'datetime',
        'next_check_at' => 'datetime',
        'publishing_started_at' => 'datetime',
        'published_at' => 'datetime',
        'failed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function idea(): BelongsTo
    {
        return $this->belongsTo(SmmIdea::class, 'idea_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
