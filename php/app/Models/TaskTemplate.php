<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Таблица task_templates.
 */
class TaskTemplate extends Model
{
    protected $table = 'task_templates';

    public $timestamps = false;

    protected $casts = [
        'start_offset_days' => 'integer',
        'duration_days' => 'integer',
        'sort' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProjectTemplate::class, 'project_template_id');
    }
}
