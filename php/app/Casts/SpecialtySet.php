<?php

namespace App\Casts;

use App\Services\Projects\ProjectAccess;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Колонка MySQL SET со специальностями (project_members.roles, employees.specialties) ↔ список.
 * В базе значения хранятся в порядке справочника, пустой список — NULL.
 *
 * @implements CastsAttributes<list<string>, iterable<string>|string|null>
 */
final class SpecialtySet implements CastsAttributes
{
    /** Уникальные допустимые специальности в порядке, в котором их выбрали. */
    public static function normalize(iterable|string|null $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $items = is_string($value) ? explode(',', $value) : $value;
        $out = [];
        foreach ($items as $s) {
            $s = is_string($s) ? trim($s) : null;
            if ($s && in_array($s, ProjectAccess::SPECIALTIES, true) && ! in_array($s, $out, true)) {
                $out[] = $s;
            }
        }

        return $out;
    }

    /** Порядок справочника — как MySQL отдаёт значения SET. */
    public static function sorted(iterable|string|null $value): array
    {
        return array_values(array_intersect(ProjectAccess::SPECIALTIES, self::normalize($value)));
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        return self::sorted($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $list = self::sorted(is_iterable($value) || is_string($value) ? $value : null);

        return $list ? implode(',', $list) : null;
    }
}
