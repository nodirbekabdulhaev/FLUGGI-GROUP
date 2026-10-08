<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Secure upload: content-sniffed type whitelist, random file name, stored on the private disk (outside public/). */
class Upload
{
    public const ALLOWED = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    public static function store(UploadedFile $file, string $folder, string $field = 'file', array $allowed = self::ALLOWED): string
    {
        $ext = strtolower((string) $file->guessExtension());
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (! in_array($ext, $allowed, true) || $file->getSize() > 5 * 1024 * 1024) {
            throw ValidationException::withMessages([$field => 'Недопустимый файл (разрешены '.implode(', ', $allowed).', до 5 МБ).']);
        }

        return $file->storeAs($folder.'/'.Tenant::id(), Str::random(40).'.'.$ext, 'local');
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }
}
