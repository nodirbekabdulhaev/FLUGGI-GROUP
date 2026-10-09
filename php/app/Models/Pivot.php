<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

/** Таблица-связка с составным ключом: изменения — через query builder (where по обоим ключам). */
abstract class Pivot extends Eloquent
{
    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.v';
}
