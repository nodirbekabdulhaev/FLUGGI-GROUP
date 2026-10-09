<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Таблица permissions.
 */
class Permission extends Model
{
    protected $table = 'permissions';

    public $timestamps = false;

    protected $casts = [

    ];

    public function roles(): HasMany
    {
        return $this->hasMany(RolePermission::class, 'permission_id');
    }
}
