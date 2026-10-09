<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Таблица clients.
 */
class Client extends Model
{
    use SoftDeletes;

    protected $table = 'clients';

    protected $casts = [
        'number' => 'integer',
        'requisites' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'client_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'client_id');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'client_id');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class, 'client_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'client_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'client_id');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class, 'client_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'client_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'client_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'client_id');
    }

    public function otherIncomes(): HasMany
    {
        return $this->hasMany(OtherIncome::class, 'client_id');
    }

    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class, 'client_id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'client_id');
    }
}
