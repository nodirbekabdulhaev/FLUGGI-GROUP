<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Начисление за опубликованный ролик SMM (видеограф, мобилограф, монтажёр): сумма — по ставке на момент начисления.
 * Не удаляется: при снятии публикации или смене исполнителя — сторно (REVERSED) с причиной.
 */
class SmmVideoAccrual extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'smm_video_accruals';

    protected $casts = [
        'amount_uzs' => 'decimal:2',
        'active' => 'boolean',
        'created_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function idea(): BelongsTo
    {
        return $this->belongsTo(SmmIdea::class, 'idea_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }
}
