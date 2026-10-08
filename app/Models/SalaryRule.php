<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class SalaryRule extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = ['id'];
    protected $casts = ['rate' => 'float', 'amount' => 'float', 'units' => 'float', 'paid_at' => 'date', 'is_active' => 'boolean'];

    public function payable()
    {
        return $this->morphTo();
    }
}
