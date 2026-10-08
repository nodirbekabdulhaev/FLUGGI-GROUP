<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use Auditable, BelongsToOrganization, BranchScoped;

    protected $table = 'attendance';
    protected $guarded = ['id'];

    public const STATUSES = [
        'present' => 'Был',
        'absent' => 'Не был',
        'late' => 'Опоздал',
        'excused' => 'Уваж. причина',
    ];

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
