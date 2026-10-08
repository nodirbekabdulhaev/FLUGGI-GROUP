<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Scopes\TenantScope;
use App\Support\Tenant;

trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            // Inside a tenant context the organization is always forced: no cross-tenant writes.
            if ($id = Tenant::id()) {
                $model->organization_id = $id;
            }
        });
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
