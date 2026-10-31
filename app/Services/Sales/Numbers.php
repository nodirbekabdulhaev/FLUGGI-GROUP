<?php

namespace App\Services\Sales;

use App\Support\Format;

/** Номера документов продаж — как в прежней версии: KP-00001, ДГ-00001, PAY-00001, D-00001, P-00001. */
final class Numbers
{
    public static function proposal(?int $n): string
    {
        return Format::code('KP', $n);
    }

    public static function contract(?int $n): string
    {
        return Format::code('ДГ', $n);
    }

    public static function payment(?int $n): string
    {
        return Format::code('PAY', $n);
    }

    public static function deal(?int $n): string
    {
        return Format::code('D', $n);
    }

    public static function project(?int $n): string
    {
        return Format::code('P', $n);
    }

    /** Ставка без лишних нулей: «10.0000» → «10», «4.5000» → «4.5». */
    public static function rate(string|int|float|null $v): string
    {
        if ($v === null || $v === '') {
            return '0';
        }
        $s = (string) $v;
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return $s === '' || $s === '-' ? '0' : $s;
    }
}
