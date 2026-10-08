<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ExpulsionReason extends Model
{
    use Auditable, BelongsToOrganization;

    protected $table = 'expulsion_reasons';
    protected $guarded = ['id'];
}
