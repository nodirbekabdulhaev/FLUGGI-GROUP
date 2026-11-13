<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Личное дело сотрудника (не проектная задача): звонок, письмо, отчёт, платёж.
 */
class Todo extends Model
{
    use SoftDeletes;

    protected $table = 'todos';

    protected $casts = [
        'number' => 'integer',
        'due_at' => 'datetime',
        'recurring_due' => 'date',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id')->withTrashed(); // удалённая запись видна в истории связанных
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id')->withTrashed(); // удалённая запись видна в истории связанных
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id')->withTrashed(); // удалённая запись видна в истории связанных
    }

    public function smmIdea(): BelongsTo
    {
        return $this->belongsTo(SmmIdea::class, 'smm_idea_id')->withTrashed(); // удалённая запись видна в истории связанных
    }

    public function recurring(): BelongsTo
    {
        return $this->belongsTo(RecurringTodo::class, 'recurring_id');
    }
}
