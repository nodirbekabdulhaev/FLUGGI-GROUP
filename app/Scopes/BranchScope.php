<?php

namespace App\Scopes;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class BranchScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($branch = Tenant::branchId()) {
            $col = $model->qualifyColumn('branch_id');
            $builder->where(fn ($q) => $q->where($col, $branch)->orWhereNull($col));
        }
    }
}
