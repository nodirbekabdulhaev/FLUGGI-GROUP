<?php

namespace App\Services\Finance;

use Illuminate\Http\Request;

/** Нормализация ввода сумм из форм: «1 500 000,5» → «1500000.5» (единая для всех модулей). */
final class Input
{
    public const MONEY = 'regex:/^\d{1,16}(\.\d{1,2})?$/';

    public static function money(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        // Пробелы (в том числе неразрывные — так их ставит форматирование поля в браузере) и запятая как разделитель дробной части
        $clean = str_replace(',', '.', (string) preg_replace('/[\s\x{00A0}\x{202F}\x{2009}]+/u', '', (string) $v));

        return $clean === '' ? null : $clean;
    }

    /** Нормализует перечисленные поля запроса перед валидацией. */
    public static function normalize(Request $request, array $fields): void
    {
        $merge = [];
        foreach ($fields as $f) {
            if ($request->has($f)) {
                $merge[$f] = self::money($request->input($f));
            }
        }
        $request->merge($merge);
    }
}
