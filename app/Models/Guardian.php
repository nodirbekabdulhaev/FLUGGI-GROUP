<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guardian extends Model
{
    use \App\Models\Concerns\NormalizesPhone;
    use Auditable, BelongsToOrganization, SoftDeletes;

    protected $table = 'parents';
    protected $guarded = ['id'];

    public function children()
    {
        return $this->belongsToMany(Student::class, 'student_parents', 'parent_id', 'student_id')
            ->withPivot('relation', 'is_primary');
    }

    public function telegramAccount()
    {
        return $this->morphOne(TelegramAccount::class, 'linkable');
    }
}
