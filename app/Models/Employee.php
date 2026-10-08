<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use \App\Models\Concerns\NormalizesPhone;
    use Auditable, BelongsToOrganization, BranchScoped, SoftDeletes;

    protected $guarded = ['id'];
    protected $casts = ['rate' => 'float', 'hired_at' => 'date'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function telegramAccount()
    {
        return $this->morphOne(TelegramAccount::class, 'linkable');
    }

    protected static function booted(): void
    {
        static::saved(fn (self $e) => app(\App\Services\SalaryService::class)->syncRule($e));
    }
}
