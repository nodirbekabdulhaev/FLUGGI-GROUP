<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Scopes\BranchScope;

trait BranchScoped
{
    public static function bootBranchScoped(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
