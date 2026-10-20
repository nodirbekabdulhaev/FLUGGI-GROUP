<?php

namespace App\Models;

use App\Casts\SpecialtySet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-данные сотрудника. Отделены от users, чтобы не попадать в CRM-выборки.
 * specialties — все специальности исполнителя, specialty — основная (первая выбранная).
 */
class Employee extends Model
{
    protected $table = 'employees';

    protected $casts = [
        'specialties' => SpecialtySet::class,
        'base_salary' => 'decimal:2',
        'kpi_bonus_target' => 'decimal:2',
        'video_target' => 'integer',
        'hired_at' => 'date',
        'fired_at' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $e) {
            if ($e->isDirty('specialties')) {
                $list = $e->specialties;
                if (! in_array($e->specialty, $list, true)) {
                    $e->specialty = $list[0] ?? null;
                }
            } elseif ($e->isDirty('specialty') || (! $e->exists && $e->specialty && ! $e->specialties)) {
                $e->specialties = $e->specialty ? [$e->specialty] : [];
            }
        });
    }

    /** @return list<string> специальности: основная первой */
    public function specialtyList(): array
    {
        $list = $this->specialties ?: ($this->specialty ? [$this->specialty] : []);
        if ($this->specialty && in_array($this->specialty, $list, true)) {
            $list = array_values(array_unique([$this->specialty, ...$list]));
        }

        return $list;
    }

    /**
     * Сотрудники, у которых есть хотя бы одна из специальностей.
     *
     * @param  list<string>|string  $specialties
     */
    public function scopeWithSpecialty(Builder $q, array|string $specialties): Builder
    {
        $specialties = (array) $specialties;

        return $q->where(function ($w) use ($specialties) {
            $w->whereIn('employees.specialty', $specialties ?: ['-']);
            foreach ($specialties as $s) {
                $w->orWhereRaw('FIND_IN_SET(?, `employees`.`specialties`) > 0', [$s]);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class, 'schedule_id');
    }
}
