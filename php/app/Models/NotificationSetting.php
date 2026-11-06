<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Индивидуальные настройки уведомлений (ТЗ §14). Нет строки — уведомление включено.
 */
class NotificationSetting extends Pivot
{
    protected $table = 'notification_settings';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
