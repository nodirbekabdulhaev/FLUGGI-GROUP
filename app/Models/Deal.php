<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Таблица deals.
 */
class Deal extends Model
{
    use SoftDeletes;

    protected $table = 'deals';

    protected $casts = [
        'number' => 'integer',
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'amount_uzs' => 'decimal:2',
        'probability_override' => 'integer',
        'expected_close_date' => 'date',
        'is_repeat' => 'boolean',
        'won_at' => 'datetime',
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
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

    public function lossReason(): BelongsTo
    {
        return $this->belongsTo(LossReason::class, 'loss_reason_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function lead(): HasOne
    {
        return $this->hasOne(Lead::class, 'deal_id');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'deal_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'deal_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'deal_id');
    }

    public function stageHistory(): HasMany
    {
        return $this->hasMany(StageHistory::class, 'deal_id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class, 'deal_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'deal_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'deal_id');
    }

    public function project(): HasOne
    {
        return $this->hasOne(Project::class, 'deal_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'deal_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(StoredFile::class, 'deal_id');
    }

    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class, 'deal_id');
    }

    public function followUpResults(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'result_deal_id');
    }
}
