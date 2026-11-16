<?php

namespace App\Services\Import;

use Illuminate\Support\Str;

/**
 * Временное хранение разобранных строк между предпросмотром и подтверждением:
 * storage/app/private/imports/{token}.json (сам загруженный файл не сохраняется).
 * Подтверждение «забирает» файл атомарным переименованием — повторный клик или двойная
 * отправка формы не импортирует строки второй раз. Итог импорта хранится до истечения срока.
 */
final class ImportStore
{
    /** Сколько живут предпросмотр и итог (секунды). */
    public const TTL = 7200;

    public static function dir(): string
    {
        $dir = storage_path('app/private/imports');
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir;
    }

    public static function valid(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]{40}$/', $token);
    }

    private static function path(string $token, string $suffix): string
    {
        if (! self::valid($token)) {
            abort(404);
        }

        return self::dir().'/'.$token.$suffix;
    }

    /** Сохраняет предпросмотр; возвращает токен. */
    public static function put(array $data): string
    {
        self::cleanup();
        $token = Str::random(40);
        file_put_contents(self::path($token, '.json'), json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod(self::path($token, '.json'), 0600);

        return $token;
    }

    /** Предпросмотр текущего пользователя и раздела (иначе null). */
    public static function get(string $token, string $entity, string $userId): ?array
    {
        return self::read(self::path($token, '.json'), $entity, $userId);
    }

    /**
     * Забирает предпросмотр для импорта (ровно один раз): .json → .lock.json.
     * null — уже забран (импорт идёт или выполнен) или истёк.
     */
    public static function claim(string $token, string $entity, string $userId): ?array
    {
        $src = self::path($token, '.json');
        $dst = self::path($token, '.lock.json');
        if (! self::read($src, $entity, $userId) || ! @rename($src, $dst)) {
            return null;
        }
        @touch($dst);

        return self::read($dst, $entity, $userId);
    }

    /** Итог импорта: исходные строки удаляются, остаётся только отчёт. */
    public static function finish(string $token, array $result): void
    {
        file_put_contents(self::path($token, '.result.json'), json_encode($result, JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod(self::path($token, '.result.json'), 0600);
        @unlink(self::path($token, '.lock.json'));
    }

    public static function result(string $token, string $entity, string $userId): ?array
    {
        return self::read(self::path($token, '.result.json'), $entity, $userId);
    }

    /** Импорт по токену уже начат, но ещё не закончен. */
    public static function running(string $token): bool
    {
        return is_file(self::path($token, '.lock.json'));
    }

    public static function forget(string $token): void
    {
        foreach (['.json', '.lock.json', '.result.json'] as $suffix) {
            @unlink(self::path($token, $suffix));
        }
    }

    /** Удаляет просроченные файлы (вызывается при каждой загрузке — без cron). */
    public static function cleanup(): void
    {
        foreach (glob(self::dir().'/*.json') ?: [] as $file) {
            if (@filemtime($file) < time() - self::TTL) {
                @unlink($file);
            }
        }
    }

    private static function read(string $file, string $entity, string $userId): ?array
    {
        if (! is_file($file) || @filemtime($file) < time() - self::TTL) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (! is_array($data) || ($data['entity'] ?? null) !== $entity || ($data['user_id'] ?? null) !== $userId) {
            return null;
        }

        return $data;
    }
}
