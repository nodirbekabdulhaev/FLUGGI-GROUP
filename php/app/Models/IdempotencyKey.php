<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ключи идемпотентности: повторный POST с тем же ключом не создаёт дубль.
 */
class IdempotencyKey extends Model
{
    protected $table = 'idempotency_keys';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.v';

    public const UPDATED_AT = null;

    protected $casts = [
        'status_code' => 'integer',
        'response' => 'array',
        'created_at' => 'datetime',
    ];
}
