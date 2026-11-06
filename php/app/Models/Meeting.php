<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Встреча (ТЗ §13).
 */
class Meeting extends Model
{
    protected $table = 'meetings';

    protected $casts = [
        'starts_at' => 'datetime',
        'duration_min' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id')->withTrashed(); // удалённая запись видна в истории связанных
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id')->withTrashed(); // удалённая запись видна в истории связанных
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id')->withTrashed(); // удалённая запись видна в истории связанных
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function rop(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rop_id');
    }

    /** Участники встречи (meeting_participants); manager_id — ответственный за результат, всегда среди них. */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'meeting_participants', 'meeting_id', 'user_id')->withTrashed()->orderBy('full_name');
    }

    /** @return list<string> */
    public function participantIds(): array
    {
        return DB::table('meeting_participants')->where('meeting_id', $this->id)->pluck('user_id')->all();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'meeting_id');
    }
}
