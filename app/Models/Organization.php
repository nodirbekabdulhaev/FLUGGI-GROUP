<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use Auditable, SoftDeletes;

    protected $guarded = ['id'];
    protected $casts = ['is_active' => 'boolean'];

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }
}
