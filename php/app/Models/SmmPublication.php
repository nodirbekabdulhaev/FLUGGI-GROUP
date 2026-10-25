<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Автопубликация идеи ролика: файл, подпись, время и места публикации (targets: Instagram, Telegram-каналы).
 * status — общий статус по местам (SmmTargets::settle): DRAFT → SCHEDULED → PUBLISHING → PUBLISHED / FAILED.
 * Файл — storage/app/smm-media (media_path, обложка cover_path, необязательная версия для Telegram tg_media_path);
 * удаляется, когда все места опубликованы (file_deleted_at), метаданные остаются.
 * Столбцы container_id, ig_media_id, permalink, error, attempts, next_check_at, publishing_started_at, published_at,
 * failed_at — прежнее состояние Instagram, перенесено в smm_publication_targets и больше не используется.
 */
class SmmPublication extends Model
{
    protected $table = 'smm_publications';

    protected $casts = [
        'share_to_feed' => 'boolean',
        'thumb_offset_ms' => 'integer',
        'media_size' => 'integer',
        'cover_size' => 'integer',
        'tg_media_size' => 'integer',
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

    public function targets(): HasMany
    {
        return $this->hasMany(SmmPublicationTarget::class, 'publication_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
