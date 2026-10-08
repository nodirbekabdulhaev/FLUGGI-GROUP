<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    use Auditable, BelongsToOrganization;

    protected $table = 'expense_categories';
    protected $guarded = ['id'];
}
