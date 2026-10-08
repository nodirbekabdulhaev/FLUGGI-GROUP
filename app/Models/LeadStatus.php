<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class LeadStatus extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = ['id'];
    protected $casts = ['is_lost' => 'boolean'];

    public const STAGES = [
        0 => 'Без этапа',
        1 => 'Новый',
        2 => 'Связались',
        3 => 'Записан',
        4 => 'Пришёл',
        5 => 'Купил',
    ];

    public const COLORS = ['slate', 'blue', 'indigo', 'amber', 'emerald', 'rose'];
}
