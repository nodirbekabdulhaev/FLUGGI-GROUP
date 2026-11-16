<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/** Отклик на вакансию с сайта. */
class VacancyApplication extends Model
{
    use SoftDeletes;

    protected $table = 'vacancy_applications';

    protected $casts = ['number' => 'integer', 'utm' => 'array', 'created_at' => 'datetime', 'updated_at' => 'datetime', 'deleted_at' => 'datetime'];
}
