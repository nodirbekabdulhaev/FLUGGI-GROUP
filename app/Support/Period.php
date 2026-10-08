<?php

namespace App\Support;

use Carbon\Carbon;

class Period
{
    public const LABELS = [
        'today' => 'Сегодня',
        'yesterday' => 'Вчера',
        'week' => 'Текущая неделя',
        'month' => 'Текущий месяц',
        'last_month' => 'Прошлый месяц',
        'year' => 'Текущий год',
        'custom' => 'Произвольный период',
    ];

    /** @return array{0:Carbon,1:Carbon,2:string} from, to (inclusive end of day), key */
    public static function resolve(?string $key, ?string $from = null, ?string $to = null, string $default = 'month'): array
    {
        $key = array_key_exists((string) $key, self::LABELS) ? $key : $default;
        $now = Carbon::now();

        switch ($key) {
            case 'today':
                [$f, $t] = [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
                break;
            case 'yesterday':
                $y = $now->copy()->subDay();
                [$f, $t] = [$y->copy()->startOfDay(), $y->copy()->endOfDay()];
                break;
            case 'week':
                [$f, $t] = [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()];
                break;
            case 'last_month':
                $m = $now->copy()->subMonthNoOverflow();
                [$f, $t] = [$m->copy()->startOfMonth(), $m->copy()->endOfMonth()];
                break;
            case 'year':
                [$f, $t] = [$now->copy()->startOfYear(), $now->copy()->endOfYear()];
                break;
            case 'custom':
                try {
                    $f = Carbon::parse($from)->startOfDay();
                    $t = Carbon::parse($to ?: $from)->endOfDay();
                    if ($t->lt($f)) {
                        [$f, $t] = [$t->copy()->startOfDay(), $f->copy()->endOfDay()];
                    }
                } catch (\Throwable) {
                    [$f, $t] = [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
                    $key = 'month';
                }
                break;
            default:
                [$f, $t] = [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
        }

        return [$f, $t, $key];
    }
}
