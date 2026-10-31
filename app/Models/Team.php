<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Отдел продаж. Отделов может быть несколько, у каждого свой РОП (head).
 */
class Team extends Model
{
    use SoftDeletes;

    protected $table = 'teams';

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(User::class, 'team_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'team_id');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'team_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'team_id');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'team_id');
    }
}
