<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;

class GroupStudent extends Model
{
    use Auditable, BelongsToOrganization, BranchScoped;

    protected $table = 'group_students';
    protected $guarded = ['id'];
    protected $casts = [
        'joined_at' => 'date',
        'left_at' => 'date',
        'next_payment_date' => 'date',
        'price' => 'float',
        'discount' => 'float',
    ];

    public const STATUSES = [
        'active' => 'Учится',
        'frozen' => 'Заморожен',
        'completed' => 'Завершил',
        'left' => 'Выбыл',
        'transferred' => 'Переведён',
    ];

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function debt()
    {
        return $this->hasOne(Debt::class);
    }

    public function previous()
    {
        return $this->belongsTo(self::class, 'transferred_from_id');
    }

    public function charge(): float
    {
        return max(0, $this->price - $this->discount);
    }

    public function isCurrent(): bool
    {
        return in_array($this->status, ['active', 'frozen'], true);
    }
}
