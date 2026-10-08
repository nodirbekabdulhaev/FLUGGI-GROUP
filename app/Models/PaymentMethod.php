<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use Auditable, BelongsToOrganization;

    protected $table = 'payment_methods';
    protected $guarded = ['id'];
}
