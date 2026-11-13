<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Системные настройки (порог крупной сделки, интервалы follow-up и т.п.).
 */
class Setting extends Model
{
    protected $table = 'settings';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.v';

    public const CREATED_AT = null;

    protected $casts = [
        'value' => 'array',
        'updated_at' => 'datetime',
    ];
}
