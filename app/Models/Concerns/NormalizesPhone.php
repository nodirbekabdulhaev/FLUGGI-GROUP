<?php

namespace App\Models\Concerns;

trait NormalizesPhone
{
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }
        // Uzbek local format (9 digits) or 8-leading → +998…
        if (strlen($digits) === 9) {
            $digits = '998'.$digits;
        }

        return '+'.$digits;
    }

    public static function bootNormalizesPhone(): void
    {
        static::saving(function ($model) {
            foreach ($model->phoneFields ?? ['phone'] as $f) {
                if (array_key_exists($f, $model->getAttributes())) {
                    $model->{$f} = static::normalizePhone($model->{$f});
                }
            }
        });
    }
}
