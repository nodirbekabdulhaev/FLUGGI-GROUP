<?php

namespace App\Auth;

/**
 * Пароли: argon2id с параметрами OWASP (19 MiB, 2 итерации) — те же хэши, что у прежней
 * версии на Node.js, поэтому пароли сотрудников после перехода остаются прежними.
 * Если PHP хостинга собран без argon2 — проверка через libsodium, новые хэши — bcrypt.
 */
final class Passwords
{
    private const OPTIONS = ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1];

    public static function hash(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID, self::OPTIONS);
        }

        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verify(?string $hash, string $password): bool
    {
        if ($hash === null || $hash === '') {
            // Выравнивание времени ответа, когда пользователь не найден
            password_verify($password, self::dummy());

            return false;
        }
        if (str_starts_with($hash, '$argon2id$') && ! defined('PASSWORD_ARGON2ID') && function_exists('sodium_crypto_pwhash_str_verify')) {
            return sodium_crypto_pwhash_str_verify($hash, $password);
        }

        return password_verify($password, $hash);
    }

    /** Временный пароль: 14 символов, гарантированно буквы и цифры. */
    public static function temporary(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $bytes = random_bytes(12);
        $body = '';
        foreach (str_split($bytes) as $b) {
            $body .= $alphabet[ord($b) % strlen($alphabet)];
        }

        return $body.(2 + ord($bytes[0]) % 8).'x';
    }

    private static function dummy(): string
    {
        static $dummy;

        return $dummy ??= self::hash('dummy-password-for-timing');
    }
}
