<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model as Eloquent;

/**
 * Базовая модель: UUID (CHAR(36)) в качестве ключа, время в UTC с миллисекундами (DATETIME(3)).
 * Массовое заполнение разрешено — входные данные всегда проходят валидацию в Request-классах.
 */
abstract class Model extends Eloquent
{
    use HasUuids;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.v';
}
