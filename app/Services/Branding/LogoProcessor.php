<?php

namespace App\Services\Branding;

use App\Exceptions\BusinessRule;
use App\Support\Brand;
use GdImage;
use Illuminate\Support\Facades\File;

/**
 * Набор иконок из загруженного логотипа (GD): квадрат по центру → PNG 32, 180, 192, 512 и maskable 512.
 * Иконки «на экран» iPhone и maskable — на белом фоне (прозрачность там показывается чёрной).
 */
final class LogoProcessor
{
    public const MIN_SIDE = 192;

    /** Больше — слишком много памяти на виртуальном хостинге (4096² × 4 байта ≈ 64 МБ). */
    public const MAX_SIDE = 4096;

    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring') && function_exists('imagepng');
    }

    /**
     * Создаёт новую версию набора иконок и возвращает её; при ошибке ничего не остаётся на диске.
     *
     * @throws BusinessRule
     */
    public static function process(string $sourcePath): string
    {
        if (! self::available()) {
            throw new BusinessRule(t('branding.errors.noGd'), ['logo' => t('branding.errors.noGd')]);
        }
        $info = @getimagesize($sourcePath);
        if (! $info || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw new BusinessRule(t('branding.errors.unreadable'), ['logo' => t('branding.errors.unreadable')]);
        }
        [$w, $h] = $info;
        if (min($w, $h) < self::MIN_SIDE) {
            throw new BusinessRule(t('branding.errors.tooSmall', ['min' => self::MIN_SIDE]), ['logo' => t('branding.errors.tooSmall', ['min' => self::MIN_SIDE])]);
        }
        if (max($w, $h) > self::MAX_SIDE) {
            throw new BusinessRule(t('branding.errors.tooLarge', ['max' => self::MAX_SIDE]), ['logo' => t('branding.errors.tooLarge', ['max' => self::MAX_SIDE])]);
        }

        $src = @imagecreatefromstring((string) file_get_contents($sourcePath));
        if (! $src instanceof GdImage) {
            throw new BusinessRule(t('branding.errors.unreadable'), ['logo' => t('branding.errors.unreadable')]);
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            $src = self::orient($src, $sourcePath);
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $x = intdiv($w - $side, 2);
        $y = intdiv($h - $side, 2);

        $version = now()->utc()->format('YmdHis').'-'.bin2hex(random_bytes(3));
        $dir = Brand::dir($version);
        File::ensureDirectoryExists($dir, 0755);
        try {
            foreach (Brand::FILES as $file => [$size, $opaque]) {
                $dst = imagecreatetruecolor($size, $size);
                if ($opaque) {
                    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
                    imagealphablending($dst, true);
                } else {
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);
                    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
                }
                imagecopyresampled($dst, $src, 0, 0, $x, $y, $size, $size, $side, $side);
                if (! imagepng($dst, $dir.'/'.$file.'.png', 6)) {
                    throw new BusinessRule(t('branding.errors.writeFailed'), ['logo' => t('branding.errors.writeFailed')]);
                }
            }
        } catch (\Throwable $e) {
            File::deleteDirectory($dir);
            throw $e instanceof BusinessRule ? $e : new BusinessRule(t('branding.errors.writeFailed'), ['logo' => t('branding.errors.writeFailed')]);
        }

        return $version;
    }

    /** Удаляет наборы иконок, кроме текущего. */
    public static function prune(?string $keep): void
    {
        if (! is_dir(Brand::root())) {
            return;
        }
        foreach (File::directories(Brand::root()) as $dir) {
            if (basename($dir) !== $keep && preg_match(Brand::VERSION_PATTERN, basename($dir))) {
                File::deleteDirectory($dir);
            }
        }
    }

    /** Фото с телефона: поворот по EXIF. */
    private static function orient(GdImage $img, string $path): GdImage
    {
        $o = function_exists('exif_read_data') ? (@exif_read_data($path)['Orientation'] ?? 1) : 1;
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;

        return $angle ? (imagerotate($img, $angle, 0) ?: $img) : $img;
    }
}
