<?php

use App\Auth\Access;
use App\Support\Format;
use App\Support\Lang;

if (! function_exists('t')) {
    /** Перевод интерфейса: t('leads.title'), t('common.pageOf', ['from' => 1, 'to' => 20, 'total' => 50]). */
    function t(string $key, array $params = []): string
    {
        return Lang::get($key, $params);
    }
}

if (! function_exists('access')) {
    /** Права текущего пользователя; без входа — 401 (или null при $required = false). */
    function access(bool $required = true): ?Access
    {
        $access = app()->bound(Access::class) ? app(Access::class) : null;
        if ($access === null && $required) {
            abort(401);
        }

        return $access;
    }
}

if (! function_exists('can')) {
    function can(string $code, string $minScope = 'OWN'): bool
    {
        return access(false)?->can($code, $minScope) ?? false;
    }
}

if (! function_exists('money')) {
    function money(mixed $amount, string $currency = 'UZS'): string
    {
        return Format::money($amount === null ? null : (string) $amount, $currency);
    }
}

if (! function_exists('dt')) {
    /** Дата и время по Ташкенту. */
    function dt(mixed $value): string
    {
        return Format::dateTime($value);
    }
}

if (! function_exists('d')) {
    /** Дата. */
    function d(mixed $value): string
    {
        return Format::date($value);
    }
}
