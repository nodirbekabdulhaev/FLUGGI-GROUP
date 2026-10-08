<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = static::allForTenant();

        return array_key_exists($key, $all) && $all[$key] !== null && $all[$key] !== '' ? $all[$key] : $default;
    }

    public static function put(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        unset(static::$cache[Tenant::id()]);
    }

    protected static array $cache = [];

    public static function allForTenant(): array
    {
        if (! Tenant::id()) {
            return [];
        }

        return static::$cache[Tenant::id()] ??= static::query()->pluck('value', 'key')->all();
    }

    public static function flush(): void
    {
        static::$cache = [];
    }
}
