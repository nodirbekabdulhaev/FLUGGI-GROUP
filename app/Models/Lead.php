<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Лид (ТЗ §8): входящий запрос до квалификации. После квалификации
 * конвертируется в клиента + сделку (docs/BUSINESS_RULES.md §2).
 */
class Lead extends Model
{
    use SoftDeletes;

    protected $table = 'leads';

    protected $casts = [
        'number' => 'integer',
        'budget' => 'decimal:2',
        'budget_uzs' => 'decimal:2',
        'desired_date' => 'date',
        'interest' => 'integer',
        'score' => 'integer',
        'next_contact_at' => 'datetime',
        'last_contact_at' => 'datetime',
        'converted_at' => 'datetime',
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(DealStage::class, 'stage_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    public function lossReason(): BelongsTo
    {
        return $this->belongsTo(LossReason::class, 'loss_reason_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'lead_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'lead_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'lead_id');
    }

    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class, 'lead_id');
    }

    public function stageHistory(): HasMany
    {
        return $this->hasMany(StageHistory::class, 'lead_id');
    }

    public function formSubmissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class, 'lead_id');
    }

    public function socialThreads(): HasMany
    {
        return $this->hasMany(SocialThread::class, 'lead_id');
    }
}
