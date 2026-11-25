<?php

namespace App\Models;

/**
 * Журнал умных напоминаний (ТЗ §41): ключ не даёт напомнить дважды об одном и том же.
 */
class ReminderLog extends Model
{
    protected $table = 'reminder_log';

    public const UPDATED_AT = null;

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
