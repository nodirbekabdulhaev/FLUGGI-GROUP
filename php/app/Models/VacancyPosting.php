<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/** Вакансия компании (публикуется на сайте). */
class VacancyPosting extends Model
{
    use SoftDeletes;

    protected $table = 'vacancy_postings';

    protected $casts = ['sort' => 'integer', 'published_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime', 'deleted_at' => 'datetime'];
}
