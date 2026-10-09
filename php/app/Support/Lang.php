<?php

namespace App\Support;

use MessageFormatter;

/**
 * Переводы интерфейса: lang/{ru,uz}/<группа>.json (вложенные ключи), формат ICU
 * ({name}, {count, plural, one {…} few {…} many {…} other {…}}). Ключ: «группа.путь.к.строке».
 * Нет перевода на узбекский — показывается русский текст; нет ключа — сам ключ.
 */
final class Lang
{
    public const LOCALES = ['uz', 'ru'];

    public const FALLBACK = 'ru';

    /** @var array<string,array> */
    private static array $groups = [];

    public static function get(string $key, array $params = [], ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $message = self::lookup($locale, $key) ?? self::lookup(self::FALLBACK, $key);
        if (! is_string($message)) {
            return $key;
        }

        return $params || str_contains($message, '{') ? self::format($message, $params, $locale) : $message;
    }

    public static function has(string $key, ?string $locale = null): bool
    {
        return is_string(self::lookup($locale ?? app()->getLocale(), $key));
    }

    /** Вся группа (для передачи в JavaScript). */
    public static function group(string $group, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        return array_replace_recursive(self::load(self::FALLBACK, $group), self::load($locale, $group));
    }

    private static function lookup(string $locale, string $key): mixed
    {
        [$group, $path] = array_pad(explode('.', $key, 2), 2, null);
        $node = self::load($locale, $group);
        if ($path === null) {
            return null;
        }
        foreach (explode('.', $path) as $part) {
            if (! is_array($node) || ! array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }

        return $node;
    }

    private static function load(string $locale, string $group): array
    {
        $id = "$locale/$group";
        if (! isset(self::$groups[$id])) {
            $file = lang_path("$locale/$group.json");
            self::$groups[$id] = is_file($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
        }

        return self::$groups[$id];
    }

    private static function format(string $message, array $params, string $locale): string
    {
        if (class_exists(MessageFormatter::class)) {
            $out = MessageFormatter::formatMessage($locale === 'uz' ? 'uz_Latn' : 'ru_RU', $message, $params);
            if ($out !== false) {
                return $out;
            }
        }

        // Без intl: простая подстановка {name}
        return preg_replace_callback('/\{(\w+)\}/', fn ($m) => array_key_exists($m[1], $params) ? (string) $params[$m[1]] : $m[0], $message);
    }
}
