<?php

namespace App\Models;

use App\Support\Format;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Идея ролика для собственного SMM компании: идея → утверждение (проект-менеджер и CEO) →
 * съёмка → монтаж → запланировано → опубликовано (или отклонено с причиной).
 */
class SmmIdea extends Model
{
    use SoftDeletes;

    protected $table = 'smm_ideas';

    protected $casts = [
        'number' => 'integer',
        'sort_order' => 'float',
        'pm_approved_at' => 'datetime',
        'ceo_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'planned_publish_date' => 'date',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** Видеограф: опубликованный ролик засчитывается и ему (план роликов в зарплате). */
    public function videographer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'videographer_id');
    }

    public function pmApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pm_approved_by_id');
    }

    public function ceoApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ceo_approved_by_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(SmmIdeaComment::class, 'idea_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(SmmIdeaHistory::class, 'idea_id');
    }

    /** Автопубликация в Instagram (одна на идею). */
    public function publication(): HasOne
    {
        return $this->hasOne(SmmPublication::class, 'idea_id');
    }

    /** V-00001 */
    public function code(): string
    {
        return Format::code('V', $this->number);
    }
}
