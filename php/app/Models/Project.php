<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Проект (ТЗ §20). Создаётся автоматически при первой подтверждённой оплате сделки (Rule 3).
 * Управление проектом (команда, задачи, Kanban) — Phase 4.
 */
class Project extends Model
{
    use SoftDeletes;

    protected $table = 'projects';

    protected $casts = [
        'number' => 'integer',
        'price' => 'decimal:2',
        'price_uzs' => 'decimal:2',
        'start_date' => 'date',
        'deadline' => 'date',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    public function rop(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rop_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProjectTemplate::class, 'template_id');
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class, 'direction_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'project_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class, 'project_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'project_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'project_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(StoredFile::class, 'project_id');
    }

    public function costLines(): HasMany
    {
        return $this->hasMany(ProjectCostLine::class, 'project_id');
    }

    public function otherIncomes(): HasMany
    {
        return $this->hasMany(OtherIncome::class, 'project_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'project_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'project_id');
    }
}
