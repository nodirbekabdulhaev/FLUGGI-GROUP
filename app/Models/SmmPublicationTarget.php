<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Место автопубликации идеи: INSTAGRAM или TELEGRAM (канал). Свой статус DRAFT → SCHEDULED → PUBLISHING → PUBLISHED / FAILED,
 * ошибка, id поста/сообщения (external_id), ссылка, попытки. Telegram: sent_parts — сколько продолжений подписи уже отправлено.
 */
class SmmPublicationTarget extends Model
{
    protected $table = 'smm_publication_targets';

    protected $casts = [
        'sent_parts' => 'integer',
        'attempts' => 'integer',
        'scheduled_at' => 'datetime',
        'next_check_at' => 'datetime',
        'started_at' => 'datetime',
        'published_at' => 'datetime',
        'failed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function publication(): BelongsTo
    {
        return $this->belongsTo(SmmPublication::class, 'publication_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(SmmTelegramChannel::class, 'channel_id')->withTrashed();
    }

    /** «Instagram» или «Telegram · Название канала». */
    public function label(): string
    {
        return $this->kind === 'INSTAGRAM' ? 'Instagram' : 'Telegram · '.($this->channel?->title ?? '—');
    }
}
