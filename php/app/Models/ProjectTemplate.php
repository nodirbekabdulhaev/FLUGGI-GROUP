<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Шаблон проекта (ТЗ §62): набор задач, создаваемых по услуге сделки.
 */
class ProjectTemplate extends Model
{
    protected $table = 'project_templates';

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(TaskTemplate::class, 'project_template_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'template_id');
    }
}
