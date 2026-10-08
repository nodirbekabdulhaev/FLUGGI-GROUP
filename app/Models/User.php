<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use \App\Models\Concerns\NormalizesPhone;
    use Auditable, BelongsToOrganization, HasApiTokens, SoftDeletes;

    protected $guarded = ['id'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = [
        'password' => 'hashed',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    protected ?array $permissionCache = null;

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function teacher()
    {
        return $this->hasOne(Teacher::class);
    }

    public function permissionSlugs(): array
    {
        return $this->permissionCache ??= $this->roles()->with('permissions:id,slug')->get()
            ->flatMap(fn ($r) => $r->permissions->pluck('slug'))->unique()->values()->all();
    }

    public function isSuperAdmin(): bool
    {
        return $this->roles->contains('slug', 'super_admin');
    }

    public function hasPermission(string $slug): bool
    {
        return $this->isSuperAdmin() || in_array($slug, $this->permissionSlugs(), true);
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles->contains('slug', $slug);
    }

    /** Teacher-style user: sees only own groups/students/lessons. */
    public function restrictedToOwnGroups(): bool
    {
        return ! $this->hasPermission('groups.view') && $this->hasPermission('groups.view_own');
    }

    public function isBranchRestricted(): bool
    {
        return $this->branch_id !== null;
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
}
