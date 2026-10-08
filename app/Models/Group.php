<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Group extends Model
{
    use Auditable, BelongsToOrganization, BranchScoped, SoftDeletes;

    protected $table = 'groups';
    protected $guarded = ['id'];
    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'price' => 'float'];

    public const STATUSES = [
        'enrolling' => 'Набор',
        'active' => 'Активная',
        'completed' => 'Завершена',
        'archived' => 'Архив',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function enrollments()
    {
        return $this->hasMany(GroupStudent::class);
    }

    public function activeEnrollments()
    {
        return $this->hasMany(GroupStudent::class)->whereIn('status', ['active', 'frozen']);
    }

    public function students()
    {
        return $this->belongsToMany(Student::class, 'group_students')->withPivot('status', 'joined_at');
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class);
    }

    public function lessons()
    {
        return $this->hasMany(Lesson::class);
    }

    /** Effective price: group override, otherwise course price. */
    public function effectivePrice(): float
    {
        return (float) ($this->price ?? $this->course?->price ?? 0);
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
