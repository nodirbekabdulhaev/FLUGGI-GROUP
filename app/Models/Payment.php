<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use Auditable, BelongsToOrganization, BranchScoped, SoftDeletes;

    /** Refunds reduce revenue, payments/corrections add (corrections may be negative). */
    public const SIGNED_SQL = "CASE WHEN payments.type = 'refund' THEN -payments.amount ELSE payments.amount END";

    public const TYPES = ['payment' => 'Оплата', 'refund' => 'Возврат', 'correction' => 'Коррекция'];

    protected $guarded = ['id'];
    protected $casts = ['amount' => 'float', 'paid_at' => 'datetime'];

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

    public function method()
    {
        return $this->belongsTo(PaymentMethod::class, 'method_id');
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function enrollment()
    {
        return $this->belongsTo(GroupStudent::class, 'group_student_id');
    }

    public function getSignedAmountAttribute(): float
    {
        return $this->type === 'refund' ? -$this->amount : $this->amount;
    }
}
