<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class LeadSource extends Model
{
    use Auditable, BelongsToOrganization;

    protected $table = 'lead_sources';
    protected $guarded = ['id'];
}
