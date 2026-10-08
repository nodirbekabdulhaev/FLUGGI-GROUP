<?php

use Carbon\Carbon;

if (! function_exists('money')) {
    function money(float|int|string|null $n, bool $currency = true): string
    {
        $s = number_format((float) $n, 0, '.', ' ');

        return $currency ? $s.' '.__('сум') : $s;
    }
}

if (! function_exists('fdate')) {
    function fdate(mixed $d, string $fmt = 'd.m.Y'): string
    {
        return $d ? Carbon::parse($d)->translatedFormat($fmt) : '—';
    }
}

if (! function_exists('ftime')) {
    function ftime(?string $t): string
    {
        return $t ? substr($t, 0, 5) : '';
    }
}

if (! function_exists('asset_v')) {
    /** Asset URL with a cache-busting mtime query; tolerant of a missing file. */
    function asset_v(string $path): string
    {
        $file = public_path($path);

        return asset($path).(is_file($file) ? '?v='.filemtime($file) : '');
    }
}
