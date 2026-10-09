<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Системные настройки (таблица settings, значение — JSON). Ключи и поля — те же, что у прежней
 * версии, поэтому сохранённые настройки переносятся без изменений.
 */
final class Settings
{
    public const DEFAULTS = [
        'automation' => [
            'largeAmountUzs' => 50_000_000,
            'followUps' => [
                ['days' => 30, 'kind' => 'CONTACT'],
                ['days' => 60, 'kind' => 'NEW_PROJECT'],
                ['days' => 90, 'kind' => 'REPEAT_SALE'],
            ],
            'dailyReport' => true,
            'weeklyReport' => true,
        ],
        'company' => [
            'name' => '', 'inn' => '', 'director' => '', 'accountant' => '', 'taxRegime' => 'OTHER',
            'legalName' => '', 'directorPosition' => 'Директор', 'signerGenitive' => '', 'basis' => 'Устава',
            'address' => '', 'phone' => '', 'email' => '', 'website' => '', 'bank' => '', 'mfo' => '',
            'account' => '', 'oked' => '', 'vatCode' => '',
        ],
    ];

    /** @var array<string,array> */
    private static array $cache = [];

    /** Значение настройки, дополненное значениями по умолчанию. */
    public static function get(string $key): array
    {
        if (! array_key_exists($key, self::$cache)) {
            $row = Setting::find($key);
            self::$cache[$key] = array_replace(self::DEFAULTS[$key] ?? [], is_array($row?->value) ? $row->value : []);
        }

        return self::$cache[$key];
    }

    public static function save(string $key, array $value): array
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by_id' => access(false)?->id()]);
        unset(self::$cache[$key]);

        return self::get($key);
    }

    public static function automation(): array
    {
        return self::get('automation');
    }

    public static function company(): array
    {
        return self::get('company');
    }

    public static function forget(): void
    {
        self::$cache = [];
    }
}
