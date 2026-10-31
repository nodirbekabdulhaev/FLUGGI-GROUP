<?php

namespace App\Models;

/**
 * Запуски планировщика (ТЗ §55): уникальный слот — задача выполняется один раз даже при нескольких worker'ах.
 */
class JobRun extends Model
{
    protected $table = 'job_runs';

    public $timestamps = false;

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'result' => 'array',
    ];
}
