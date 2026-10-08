<?php

namespace App\Scopes;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($id = Tenant::id()) {
            $builder->where($model->qualifyColumn('organization_id'), $id);
        }
    }
}
