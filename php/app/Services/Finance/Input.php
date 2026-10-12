<?php

namespace App\Services\Finance;

use Illuminate\Http\Request;

/** Нормализация ввода сумм из форм: «1 500 000,5» → «1500000.5». */
final class Input
{
    public const MONEY = 'regex:/^\d{1,16}(\.\d{1,2})?$/';

    public static function money(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string) $v));
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
