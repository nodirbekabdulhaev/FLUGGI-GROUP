<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;

class Debt extends Model
{
    use BelongsToOrganization, BranchScoped;

    protected $guarded = ['id'];
    protected $casts = [
        'charged' => 'float',
        'paid' => 'float',
        'balance' => 'float',
        'last_payment_at' => 'datetime',
        'next_payment_date' => 'date',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function group()
    {
        return $this->belongsTo(Group::class)->withTrashed();
    }

    public function course()
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }

    public function enrollment()
    {
        return $this->belongsTo(GroupStudent::class, 'group_student_id');
    }
}
