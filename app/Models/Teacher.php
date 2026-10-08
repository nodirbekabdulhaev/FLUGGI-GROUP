<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Teacher extends Model
{
    use \App\Models\Concerns\NormalizesPhone;
    use Auditable, BelongsToOrganization, BranchScoped, SoftDeletes;

    protected $guarded = ['id'];
    protected $casts = ['rate' => 'float', 'hired_at' => 'date'];

    public const PAY_TYPES = [
        'fixed' => 'Фиксированная (в месяц)',
        'per_lesson' => 'За занятие',
        'per_student' => 'За ученика',
        'percent' => 'Процент от оплат',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function groups()
    {
        return $this->hasMany(Group::class);
    }

    public function lessons()
    {
        return $this->hasMany(Lesson::class);
    }

    public function telegramAccount()
    {
        return $this->morphOne(TelegramAccount::class, 'linkable');
    }

    protected static function booted(): void
    {
        static::saved(fn (self $t) => app(\App\Services\SalaryService::class)->syncRule($t));
    }
}
