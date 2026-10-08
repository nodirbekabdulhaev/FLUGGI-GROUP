<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use \App\Models\Concerns\NormalizesPhone;
    use Auditable, BelongsToOrganization, SoftDeletes;

    protected $guarded = ['id'];

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }

    public function scopeActive($q)
    {
        return $q->where('status', 'active');
    }
}
