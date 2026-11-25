<?php

namespace App\Models;

/**
 * Transactional outbox: событие пишется в той же транзакции, что и изменение данных,
 * worker доставляет его подписчикам (уведомления, Telegram, пересчёты).
 */
class OutboxEvent extends Model
{
    protected $table = 'outbox_events';

    public const UPDATED_AT = null;

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
        'available_at' => 'datetime',
        'processed_at' => 'datetime',
        'attempts' => 'integer',
    ];
}
