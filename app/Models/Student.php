<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use \App\Models\Concerns\NormalizesPhone;
    use Auditable, BelongsToOrganization, BranchScoped, SoftDeletes;

    protected $guarded = ['id'];
    protected $casts = [
        'birth_date' => 'date',
        'registered_at' => 'date',
        'status_changed_at' => 'date',
    ];

    public const STATUSES = [
        'active' => 'Активный',
        'frozen' => 'Заморожен',
        'completed' => 'Завершил',
        'expelled' => 'Отчислен',
        'archived' => 'Архив',
    ];

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function guardians()
    {
        return $this->belongsToMany(Guardian::class, 'student_parents', 'student_id', 'parent_id')
            ->withPivot('relation', 'is_primary');
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function source()
    {
        return $this->belongsTo(LeadSource::class, 'source_id');
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function expulsionReason()
    {
        return $this->belongsTo(ExpulsionReason::class);
    }

    public function enrollments()
    {
        return $this->hasMany(GroupStudent::class);
    }

    public function activeEnrollment()
    {
        return $this->hasOne(GroupStudent::class)->whereIn('status', ['active', 'frozen'])->latestOfMany();
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function debts()
    {
        return $this->hasMany(Debt::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    public function events()
    {
        return $this->hasMany(StudentEvent::class)->latest('id');
    }

    public function telegramAccount()
    {
        return $this->morphOne(TelegramAccount::class, 'linkable');
    }

    /** Adds charged / paid / balance columns (balance = charged - paid, >0 means debt). */
    public function scopeWithFinance($q)
    {
        return $q->addSelect([
            'f_charged' => Debt::selectRaw('COALESCE(SUM(charged),0)')->whereColumn('debts.student_id', 'students.id'),
            'f_paid' => Payment::selectRaw('COALESCE(SUM('.Payment::SIGNED_SQL.'),0)')->whereColumn('payments.student_id', 'students.id'),
        ])->addSelect('students.*');
    }

    public function getBalanceAttribute(): float
    {
        if (array_key_exists('f_charged', $this->attributes)) {
            return (float) $this->attributes['f_charged'] - (float) $this->attributes['f_paid'];
        }

        return $this->financeSummary()['balance'];
    }

    public function financeSummary(): array
    {
        $charged = (float) $this->debts()->sum('charged');
        $paid = (float) $this->payments()->selectRaw('COALESCE(SUM('.Payment::SIGNED_SQL.'),0) as s')->value('s');

        return ['charged' => $charged, 'paid' => $paid, 'balance' => $charged - $paid];
    }

    /** Attendance percent: (present + late) / all marked. */
    public function attendanceStats(): array
    {
        $rows = $this->attendances()->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        $total = (int) $rows->sum();
        $visited = (int) (($rows['present'] ?? 0) + ($rows['late'] ?? 0));

        return [
            'total' => $total,
            'visited' => $visited,
            'missed' => (int) (($rows['absent'] ?? 0) + ($rows['excused'] ?? 0)),
            'percent' => $total ? round($visited * 100 / $total) : null,
        ];
    }

    public function scopeVisibleTo($q, User $user)
    {
        if ($user->restrictedToOwnGroups() && ! $user->hasPermission('students.view')) {
            $teacherIds = Teacher::withoutGlobalScopes()->where('user_id', $user->id)->pluck('id');

            return $q->whereHas('enrollments', fn ($e) => $e->whereIn('group_id',
                Group::withoutGlobalScopes()->whereIn('teacher_id', $teacherIds)->select('id')));
        }

        return $q;
    }
}
