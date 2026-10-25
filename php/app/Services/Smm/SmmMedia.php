<?php

namespace App\Services\Smm;

use App\Exceptions\BusinessRule;
use Illuminate\Support\Str;

/**
 * Файлы автопубликации Instagram: storage/app/smm-media (не публично).
 *  - Загрузка частями (по умолчанию 4 МБ, fetch из браузера телом запроса): большие файлы с iPhone (4K)
 *    проходят при маленьких upload_max_filesize хостинга. Части дописываются в один файл .part
 *    строго по порядку (повтор той же части безопасен), после сборки папка загрузки сразу удаляется.
 *  - Брошенные загрузки удаляет cron через chunks_keep_hours часов.
 *  - Проверка: расширение, тип по содержимому (finfo), размер; у видео MP4/MOV — длительность
 *    и размер кадра из заголовка файла (moov/mvhd/tkhd), у фото — размер и соотношение сторон.
 */
final class SmmMedia
{
    public const VIDEO = ['mp4' => ['video/mp4'], 'mov' => ['video/quicktime', 'video/mp4']];

    public const IMAGE = ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png']];

    /** Reels: длительность, секунд (Meta: от 3 с до 15 мин). */
    public const MIN_SECONDS = 3;

    public const MAX_SECONDS = 900;

    public static function root(): string
    {
        return rtrim((string) (config('fluggi.smm_media.root') ?: storage_path('app/smm-media')), '/');
    }

    public static function chunkBytes(): int
    {
        return max(1, (int) config('fluggi.smm_media.chunk_mb', 4)) * 1024 * 1024;
    }

    public static function maxBytes(string $type): int
    {
        return (int) config($type === 'video' ? 'fluggi.smm_media.max_video_mb' : 'fluggi.smm_media.max_image_mb', 8) * 1024 * 1024;
    }

    /** Полный путь к файлу из относительного (как в базе). */
    public static function path(?string $relative): ?string
    {
        if (! $relative || str_contains($relative, '..')) {
            return null;
        }

        return self::root().'/'.ltrim($relative, '/');
    }

    public static function exists(?string $relative): bool
    {
        $p = self::path($relative);

        return $p !== null && is_file($p);
    }

    public static function delete(?string $relative): void
    {
        $p = self::path($relative);
        if ($p !== null && is_file($p)) {
            @unlink($p);
        }
    }

    private static function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new BusinessRule(t('smm.ig.errors.storage'));
        }
    }

    private static function uploadDir(string $uploadId): string
    {
        abort_unless(Str::isUuid($uploadId), 404);

        return self::root().'/chunks/'.$uploadId;
    }

    /** Тип по расширению: video / image (для обложки — только image). */
    public static function typeOf(string $name, string $purpose): ?string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (isset(self::IMAGE[$ext])) {
            return 'image';
        }

        return $purpose === 'media' && isset(self::VIDEO[$ext]) ? 'video' : null;
    }

    /**
     * Начать загрузку: проверка расширения и размера до передачи данных.
     *
     * @return array{uploadId:string, chunkSize:int}
     */
    public static function start(string $ideaId, string $userId, string $purpose, string $name, int $size): array
    {
        $type = self::typeOf($name, $purpose);
        if (! $type) {
            throw new BusinessRule(t($purpose === 'cover' ? 'smm.ig.errors.coverType' : 'smm.ig.errors.mediaType'), ['file' => t($purpose === 'cover' ? 'smm.ig.errors.coverType' : 'smm.ig.errors.mediaType')]);
        }
        if ($size <= 0 || $size > self::maxBytes($type)) {
            $msg = t('smm.ig.errors.tooLarge', ['mb' => (int) (self::maxBytes($type) / 1024 / 1024)]);
            throw new BusinessRule($msg, ['file' => $msg]);
        }
        $id = (string) Str::uuid();
        $dir = self::uploadDir($id);
        self::ensureDir($dir);
        file_put_contents($dir.'/meta.json', json_encode([
            'ideaId' => $ideaId, 'userId' => $userId, 'purpose' => $purpose, 'type' => $type,
            'name' => mb_substr(basename($name), 0, 200), 'size' => $size, 'createdAt' => time(),
        ]));
        touch($dir.'/data.part');

        return ['uploadId' => $id, 'chunkSize' => self::chunkBytes()];
    }

    /** Данные загрузки; чужая или неизвестная — 404. */
    public static function meta(string $uploadId, string $ideaId, string $userId): array
    {
        $file = self::uploadDir($uploadId).'/meta.json';
        $meta = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        abort_unless(is_array($meta) && $meta['ideaId'] === $ideaId && $meta['userId'] === $userId, 404);

        return $meta;
    }

    /**
     * Часть файла по номеру (с нуля). Повтор уже записанной части — без изменений.
     *
     * @return array{received:int, size:int}
     */
    public static function chunk(string $uploadId, string $ideaId, string $userId, int $index, string $bytes): array
    {
        $meta = self::meta($uploadId, $ideaId, $userId);
        $part = self::uploadDir($uploadId).'/data.part';
        $chunk = self::chunkBytes();
        $len = strlen($bytes);
        $offset = $index * $chunk;
        clearstatcache(true, $part);
        $have = (int) @filesize($part);
        if ($len === 0 || $len > $chunk || $offset + $len > $meta['size']) {
            throw new BusinessRule(t('smm.ig.errors.chunk'));
        }
        if ($offset + $len <= $have) {
            return ['received' => $have, 'size' => $meta['size']];
        }
        if ($offset !== $have) {
            throw new BusinessRule(t('smm.ig.errors.chunkOrder'));
        }
        $fh = fopen($part, 'ab');
        if (! $fh || fwrite($fh, $bytes) !== $len) {
            throw new BusinessRule(t('smm.ig.errors.storage'));
        }
        fclose($fh);

        return ['received' => $have + $len, 'size' => $meta['size']];
    }

    /**
     * Собрать файл: размер, тип по содержимому, свойства. Готовый файл — в smm-media/{idea}/, части удаляются сразу.
     * PNG перекодируется в JPEG (Instagram принимает только JPEG) с качеством 95.
     *
     * @return array{purpose:string, type:string, path:string, name:string, size:int, mime:string, info:array}
     */
    public static function finish(string $uploadId, string $ideaId, string $userId): array
    {
        $meta = self::meta($uploadId, $ideaId, $userId);
        $dir = self::uploadDir($uploadId);
        $part = $dir.'/data.part';
        try {
            clearstatcache(true, $part);
            if ((int) @filesize($part) !== (int) $meta['size']) {
                throw new BusinessRule(t('smm.ig.errors.incomplete'));
            }
            $ext = strtolower(pathinfo($meta['name'], PATHINFO_EXTENSION));
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($part);
            $allowed = $meta['type'] === 'video' ? self::VIDEO[$ext] ?? [] : self::IMAGE[$ext] ?? [];
            if (! in_array($mime, $allowed, true)) {
                $msg = t($meta['purpose'] === 'cover' ? 'smm.ig.errors.coverType' : 'smm.ig.errors.mediaType');
                throw new BusinessRule($msg, ['file' => $msg]);
            }
            $info = $meta['type'] === 'video' ? self::probeVideo($part) : self::probeImage($part);
            if ($meta['type'] === 'video' && isset($info['seconds']) && ($info['seconds'] < self::MIN_SECONDS || $info['seconds'] > self::MAX_SECONDS)) {
                $msg = t('smm.ig.errors.duration', ['min' => self::MIN_SECONDS, 'max' => self::MAX_SECONDS / 60]);
                throw new BusinessRule($msg, ['file' => $msg]);
            }
            if ($meta['type'] === 'image' && $meta['purpose'] === 'media' && isset($info['ratio']) && ($info['ratio'] < 0.79 || $info['ratio'] > 1.92)) {
                $msg = t('smm.ig.errors.imageRatio');
                throw new BusinessRule($msg, ['file' => $msg]);
            }
            $target = $ideaId.'/'.Str::uuid();
            self::ensureDir(self::root().'/'.$ideaId);
            $name = $meta['name'];
            if ($mime === 'image/png') {
                self::pngToJpeg($part, self::root().'/'.$target.'.jpg');
                $target .= '.jpg';
                $mime = 'image/jpeg';
                $name = pathinfo($name, PATHINFO_FILENAME).'.jpg';
            } else {
                $target .= '.'.($ext === 'jpeg' ? 'jpg' : $ext);
                if (! @rename($part, self::root().'/'.$target)) {
                    throw new BusinessRule(t('smm.ig.errors.storage'));
                }
            }
            clearstatcache();

            return ['purpose' => $meta['purpose'], 'type' => $meta['type'], 'path' => $target, 'name' => $name,
                'size' => (int) filesize(self::root().'/'.$target), 'mime' => $mime, 'info' => $info];
        } finally {
            self::removeDir($dir);
        }
    }

    private static function pngToJpeg(string $from, string $to): void
    {
        if (! function_exists('imagecreatefrompng')) {
            throw new BusinessRule(t('smm.ig.errors.pngUnsupported'), ['file' => t('smm.ig.errors.pngUnsupported')]);
        }
        $src = @imagecreatefrompng($from);
        if (! $src) {
            throw new BusinessRule(t('smm.ig.errors.mediaType'), ['file' => t('smm.ig.errors.mediaType')]);
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $dst = imagecreatetruecolor($w, $h);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
        imagejpeg($dst, $to, 95);
        imagedestroy($src);
        imagedestroy($dst);
    }

    public static function probeImage(string $path): array
    {
        $s = @getimagesize($path);

        return $s ? ['width' => $s[0], 'height' => $s[1], 'ratio' => $s[1] > 0 ? round($s[0] / $s[1], 3) : null] : [];
    }

    /**
     * Длительность и размер кадра MP4/MOV по заголовку (ISO BMFF: moov → mvhd, trak → tkhd с матрицей поворота).
     * Не удалось разобрать — пусто (проверку сделает Meta).
     *
     * @return array{seconds?:float, width?:int, height?:int}
     */
    public static function probeVideo(string $path): array
    {
        $fh = @fopen($path, 'rb');
        if (! $fh) {
            return [];
        }
        try {
            $size = (int) filesize($path);
            $moov = self::findBox($fh, 0, $size, 'moov');
            if (! $moov) {
                return [];
            }
            $out = [];
            if ($mvhd = self::findBox($fh, $moov[0], $moov[1], 'mvhd')) {
                fseek($fh, $mvhd[0]);
                $h = fread($fh, 32);
                if (ord($h[0]) === 1) {
                    // version 1: [ver/flags 4][ctime 8][mtime 8][timescale 4][duration 8]
                    $timescale = unpack('N', substr($h, 20, 4))[1];
                    $duration = unpack('J', substr($h, 24, 8))[1];
                } else {
                    // version 0: [ver/flags 4][ctime 4][mtime 4][timescale 4][duration 4]
                    $timescale = unpack('N', substr($h, 12, 4))[1];
                    $duration = unpack('N', substr($h, 16, 4))[1];
                }
                if ($timescale > 0) {
                    $out['seconds'] = round($duration / $timescale, 2);
                }
            }
            // Видео-дорожка: первая tkhd с ненулевым размером кадра
            $pos = $moov[0];
            while ($pos < $moov[1] && ($trak = self::findBox($fh, $pos, $moov[1], 'trak'))) {
                $pos = $trak[1];
                $tkhd = self::findBox($fh, $trak[0], $trak[1], 'tkhd');
                if (! $tkhd) {
                    continue;
                }
                fseek($fh, $tkhd[0]);
                $version = ord(fread($fh, 1));
                // Матрица и размер — в конце: [matrix 36][width 4][height 4]
                fseek($fh, $tkhd[0] + ($version === 1 ? 52 : 40));
                $m = unpack('Na/Nb/Nu/Nc/Nd', fread($fh, 20));
                fseek($fh, $tkhd[0] + ($version === 1 ? 88 : 76));
                $wh = unpack('Nw/Nh', fread($fh, 8));
                $w = (int) ($wh['w'] >> 16);
                $h = (int) ($wh['h'] >> 16);
                if ($w > 0 && $h > 0) {
                    // Поворот на 90° (портретное видео iPhone): a = 0, b = ±1
                    $rotated = $m['a'] === 0 && $m['b'] !== 0;
                    $out['width'] = $rotated ? $h : $w;
                    $out['height'] = $rotated ? $w : $h;
                    break;
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        } finally {
            fclose($fh);
        }
    }

    /**
     * Найти коробку $type среди дочерних в [$from, $to): [начало данных, конец коробки] или null.
     *
     * @param  resource  $fh
     */
    private static function findBox($fh, int $from, int $to, string $type): ?array
    {
        $pos = $from;
        while ($pos + 8 <= $to) {
            fseek($fh, $pos);
            $head = fread($fh, 8);
            if (strlen($head) < 8) {
                return null;
            }
            $len = unpack('N', substr($head, 0, 4))[1];
            $name = substr($head, 4, 4);
            $header = 8;
            if ($len === 1) {
                $len = unpack('J', fread($fh, 8))[1];
                $header = 16;
            } elseif ($len === 0) {
                $len = $to - $pos;
            }
            if ($len < $header) {
                return null;
            }
            if ($name === $type) {
                return [$pos + $header, min($to, $pos + $len)];
            }
            $pos += $len;
        }

        return null;
    }

    private static function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                @unlink($dir.'/'.$f);
            }
        }
        @rmdir($dir);
    }

    /** Брошенные загрузки старше chunks_keep_hours часов. */
    public static function cleanupChunks(): int
    {
        $base = self::root().'/chunks';
        if (! is_dir($base)) {
            return 0;
        }
        $limit = time() - (int) config('fluggi.smm_media.chunks_keep_hours', 6) * 3600;
        $n = 0;
        foreach (scandir($base) ?: [] as $d) {
            $dir = $base.'/'.$d;
            if ($d === '.' || $d === '..' || ! is_dir($dir)) {
                continue;
            }
            $meta = is_file($dir.'/meta.json') ? json_decode((string) file_get_contents($dir.'/meta.json'), true) : null;
            $created = (int) ($meta['createdAt'] ?? @filemtime($dir));
            $touched = max($created, (int) @filemtime($dir.'/data.part'));
            if ($touched < $limit) {
                self::removeDir($dir);
                $n++;
            }
        }

        return $n;
    }

    /** Сколько места занимают файлы автопубликации (байт). */
    public static function diskUsage(): int
    {
        $root = self::root();
        if (! is_dir($root)) {
            return 0;
        }
        $total = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $total += $f->getSize();
            }
        }

        return $total;
    }

    /** «48,2 МБ». */
    public static function human(?int $bytes): string
    {
        $bytes = (int) $bytes;
        if ($bytes < 1024 * 1024) {
            return t('smm.ig.kb', ['n' => max(1, (int) round($bytes / 1024))]);
        }
        $mb = $bytes / 1024 / 1024;

        return $mb >= 1024 ? t('smm.ig.gb', ['n' => number_format($mb / 1024, 2, ',', ' ')]) : t('smm.ig.mb', ['n' => number_format($mb, 1, ',', ' ')]);
    }
}
