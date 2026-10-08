<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use Auditable;

    protected $guarded = ['id'];
    protected $casts = ['is_system' => 'boolean'];

    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    /** System roles (organization_id = null) are shared; custom roles belong to one organization. */
    public function scopeAvailable($q)
    {
        return $q->where(fn ($w) => $w->whereNull('organization_id')->orWhere('organization_id', \App\Support\Tenant::id()));
    }
}
