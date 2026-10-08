<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use Auditable, BelongsToOrganization, SoftDeletes;

    protected $guarded = ['id'];
    protected $casts = ['price' => 'float'];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class);
    }

    public function groups()
    {
        return $this->hasMany(Group::class);
    }
}
