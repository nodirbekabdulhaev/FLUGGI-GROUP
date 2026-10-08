<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];
}
