<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Таблица lead_sources.
 */
class LeadSource extends Model
{
    protected $table = 'lead_sources';

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'source_id');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'source_id');
    }

    /** Название для интерфейса: узбекское при узбекском языке (если задано), иначе русское. */
    public function label(): string
    {
        return app()->getLocale() === 'uz' && filled($this->name_uz) ? $this->name_uz : $this->name_ru;
    }
}
