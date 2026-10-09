<?php

namespace App\Support;

use Illuminate\Support\Facades\DB as Facade;

/** Особенности MySQL хостинга. */
final class Db
{
    /** SKIP LOCKED есть в MySQL 8+ и MariaDB 10.6+; на MySQL 5.7 — обычная блокировка. */
    public static function supportsSkipLocked(string $version): bool
    {
        if (preg_match('/^(\d+)\.(\d+)/', $version, $m) !== 1) {
            return false;
        }
        [$major, $minor] = [(int) $m[1], (int) $m[2]];
        if (stripos($version, 'mariadb') !== false) {
            return $major > 10 || ($major === 10 && $minor >= 6);
        }

        return $major >= 8;
    }

    public static function lockRows(): string
    {
        static $clause;

        return $clause ??= self::supportsSkipLocked((string) Facade::selectOne('SELECT VERSION() AS v')->v)
            ? 'FOR UPDATE SKIP LOCKED'
            : 'FOR UPDATE';
    }
}
