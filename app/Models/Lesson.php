<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    use Auditable, BelongsToOrganization, BranchScoped;

    protected $guarded = ['id'];
    protected $casts = ['lesson_date' => 'date', 'held_at' => 'datetime'];

    public const STATUSES = [
        'planned' => 'Запланировано',
        'held' => 'Проведено',
        'cancelled' => 'Отменено',
        'rescheduled' => 'Перенесено',
    ];

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function schedule()
    {
        return $this->belongsTo(Schedule::class);
    }

    public function attendance()
    {
        return $this->hasMany(Attendance::class);
    }

    public function scopeVisibleTo($q, User $user)
    {
        if ($user->restrictedToOwnGroups()) {
            $ids = Teacher::withoutGlobalScopes()->where('user_id', $user->id)->pluck('id');

            return $q->whereIn('teacher_id', $ids);
        }

        return $q;
    }
}
