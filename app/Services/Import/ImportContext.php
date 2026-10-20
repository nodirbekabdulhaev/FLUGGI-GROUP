<?php

namespace App\Services\Import;

/** Состояние одного прохода по файлу: выбранные опции, ключи уже встреченных строк (дубли внутри файла), кэши справочников. */
final class ImportContext
{
    /** @var array<string,int> ключ → номер строки, где он встретился впервые */
    private array $seen = [];

    /** @var array<string,mixed> */
    public array $cache = [];

    /** @param  array<string,bool>  $options */
    public function __construct(public readonly array $options = [], public readonly bool $dryRun = true) {}

    public function option(string $key): bool
    {
        return ! empty($this->options[$key]);
    }

    /** Номер более ранней строки файла с одним из ключей (или null). */
    public function seenRow(array $keys): ?int
    {
        foreach ($keys as $k) {
            if ($k !== null && isset($this->seen[$k])) {
                return $this->seen[$k];
            }
        }

        return null;
    }

    public function remember(array $keys, int $row): void
    {
        foreach ($keys as $k) {
            if ($k !== null) {
                $this->seen[$k] ??= $row;
            }
        }
    }

    /** Значение из кэша прохода (справочники, сотрудники). */
    public function cached(string $key, callable $make): mixed
    {
        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $make();
        }

        return $this->cache[$key];
    }
}
