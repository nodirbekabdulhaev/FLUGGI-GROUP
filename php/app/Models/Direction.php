<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Направление бизнеса группы: IT, Медиа (SMM, брендинг, продакшн), Маркетинг.
 */
class Direction extends Model
{
    protected $table = 'directions';

    protected $casts = [
        'sort' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'direction_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'direction_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(UserDirection::class, 'direction_id');
    }
}
