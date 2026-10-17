<?php

namespace App\Support;

use Throwable;

/**
 * Бренд интерфейса: название проекта, логотип и цвет темы (настройка «branding»).
 * Стандартный набор иконок — public/icons; загруженный логотип — storage/app/branding/<версия>/
 * (каталог storage/ не затирается обновлением), отдаётся маршрутом /brand/{файл}.
 * В шаблонах: brand()->name(), brand()->logo(), brand()->icon('favicon-32').
 */
final class Brand
{
    public const DEFAULT_THEME = '#2f6c86';

    /** Версия стандартного набора иконок в public/icons (для сброса кэша браузера). */
    public const DEFAULT_VERSION = '2';

    /** Файлы набора иконок: имя → размер, на непрозрачном фоне ли (иконка «на экран» iPhone и maskable). */
    public const FILES = [
        'favicon-32' => [32, false],
        'apple-touch-icon' => [180, true],
        'icon-192' => [192, false],
        'icon-512' => [512, false],
        'maskable-512' => [512, true],
    ];

    public const VERSION_PATTERN = '/^\d{14}-[0-9a-f]{6}$/';

    public static function settings(): array
    {
        try {
            return Settings::get('branding');
        } catch (Throwable) {
            // База недоступна (страница ошибки, установка) — стандартный бренд
            return Settings::DEFAULTS['branding'];
        }
    }

    public function name(): string
    {
        return (string) (self::settings()['name'] ?: Settings::DEFAULTS['branding']['name']);
    }

    public function shortName(): string
    {
        return (string) (self::settings()['shortName'] ?: Settings::DEFAULTS['branding']['shortName']);
    }

    public function themeColor(): string
    {
        $c = self::settings()['themeColor'] ?? null;

        return is_string($c) && preg_match('/^#[0-9a-f]{6}$/i', $c) ? strtolower($c) : self::DEFAULT_THEME;
    }

    /** Версия загруженного логотипа или null (стандартный). */
    public function version(): ?string
    {
        $v = self::settings()['version'] ?? null;

        return is_string($v) && preg_match(self::VERSION_PATTERN, $v) && is_file(self::dir($v).'/icon-192.png') ? $v : null;
    }

    public function custom(): bool
    {
        return $this->version() !== null;
    }

    /** Адрес иконки набора (с версией — браузер и PWA берут новую сразу после замены). */
    public function icon(string $file): string
    {
        $v = $this->version();

        return $v ? '/brand/'.$file.'?v='.$v : '/icons/'.$file.'.png?v='.self::DEFAULT_VERSION;
    }

    /** Логотип для шапки меню и страницы входа. */
    public function logo(): string
    {
        return $this->icon('icon-192');
    }

    /** Метка для адреса манифеста: меняется при смене бренда. */
    public function stamp(): string
    {
        return substr(md5(json_encode([$this->name(), $this->shortName(), $this->themeColor(), $this->version()])), 0, 10);
    }

    /** Файл иконки на диске: загруженный или стандартный. */
    public function path(string $file): ?string
    {
        if (! array_key_exists($file, self::FILES)) {
            return null;
        }
        $v = $this->version();
        $path = $v ? self::dir($v).'/'.$file.'.png' : public_path('icons/'.$file.'.png');

        return is_file($path) ? $path : null;
    }

    public static function root(): string
    {
        return storage_path('app/branding');
    }

    public static function dir(string $version): string
    {
        return self::root().'/'.$version;
    }
}
