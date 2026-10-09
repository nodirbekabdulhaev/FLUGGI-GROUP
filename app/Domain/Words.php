<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Support\Js;
use Brick\Math\BigDecimal;

/** Сумма прописью для договоров (рус.): «Семь миллионов пятьсот тысяч сумов 00 тийинов». */
final class Words
{
    private const ONES_M = ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];

    private const ONES_F = ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];

    private const TEENS = [
        'десять',
        'одиннадцать',
        'двенадцать',
        'тринадцать',
        'четырнадцать',
        'пятнадцать',
        'шестнадцать',
        'семнадцать',
        'восемнадцать',
        'девятнадцать',
    ];

    private const TENS = [
        '',
        '',
        'двадцать',
        'тридцать',
        'сорок',
        'пятьдесят',
        'шестьдесят',
        'семьдесят',
        'восемьдесят',
        'девяносто',
    ];

    private const HUNDREDS = [
        '',
        'сто',
        'двести',
        'триста',
        'четыреста',
        'пятьсот',
        'шестьсот',
        'семьсот',
        'восемьсот',
        'девятьсот',
    ];

    /** @var list<array{forms: array{string, string, string}, feminine: bool}> */
    private const SCALES = [
        ['forms' => ['', '', ''], 'feminine' => false],
        ['forms' => ['тысяча', 'тысячи', 'тысяч'], 'feminine' => true],
        ['forms' => ['миллион', 'миллиона', 'миллионов'], 'feminine' => false],
        ['forms' => ['миллиард', 'миллиарда', 'миллиардов'], 'feminine' => false],
        ['forms' => ['триллион', 'триллиона', 'триллионов'], 'feminine' => false],
    ];

    /** @var array<string, array{major: array{string, string, string}, minor: array{string, string, string}, feminine: bool}> */
    private const CURRENCY_WORDS = [
        'UZS' => ['major' => ['сум', 'сума', 'сумов'], 'minor' => ['тийин', 'тийина', 'тийинов'], 'feminine' => false],
        'USD' => [
            'major' => ['доллар США', 'доллара США', 'долларов США'],
            'minor' => ['цент', 'цента', 'центов'],
            'feminine' => false,
        ],
    ];

    /**
     * Форма слова для числа: 1 сум, 2 сума, 5 сумов.
     *
     * @param  array{string, string, string}  $forms
     */
    public static function plural(int|float $n, array $forms): string
    {
        [$one, $few, $many] = $forms;
        // % в JS — остаток со знаком делимого, как fmod.
        $m10 = fmod((float) $n, 10);
        $m100 = fmod((float) $n, 100);
        if ($m10 == 1 && $m100 != 11) {
            return $one;
        }
        if ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) {
            return $few;
        }

        return $many;
    }

    /** Целое число прописью. `feminine` — для единиц женского рода. */
    public static function numberInWords(int|float $value, bool $feminine = false): string
    {
        $n = floor(abs((float) $value));
        if ($n == 0) {
            return 'ноль';
        }
        $parts = [];
        $scale = 0;
        while ($n > 0 && $scale < count(self::SCALES)) {
            $t = (int) fmod($n, 1000);
            if ($t !== 0) {
                $s = self::SCALES[$scale];
                $words = self::triad($t, $scale === 0 ? $feminine : $s['feminine']);
                if ($scale > 0) {
                    $words[] = self::plural($t, $s['forms']);
                }
                array_unshift($parts, implode(' ', $words));
            }
            $n = floor($n / 1000);
            $scale++;
        }

        return implode(' ', $parts);
    }

    /** «7 500 000.00 UZS» → «Семь миллионов пятьсот тысяч сумов 00 тийинов». */
    public static function amountInWords(BigDecimal|string|int|float $amount, string $currency): string
    {
        // Number(amount).toFixed(2) — двоичное число, как в JS.
        $number = $amount instanceof BigDecimal ? (float) $amount->toString() : (float) $amount;
        $fixed = Js::toFixed($number, 2);
        [$intPart, $frac] = explode('.', str_replace('-', '', $fixed), 2) + [1 => ''];
        $major = (float) $intPart;
        $minor = (int) $frac;
        $w = self::CURRENCY_WORDS[$currency] ?? [
            'major' => [$currency, $currency, $currency],
            'minor' => ['', '', ''],
            'feminine' => false,
        ];
        $words = self::numberInWords($major, $w['feminine']);
        $text = trim("{$words} ".self::plural($major, $w['major'])." {$frac} ".self::plural($minor, $w['minor']));

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /** @return list<string> */
    private static function triad(int $n, bool $feminine): array
    {
        $out = [];
        $h = intdiv($n, 100);
        $rest = $n % 100;
        if ($h) {
            $out[] = self::HUNDREDS[$h];
        }
        if ($rest >= 10 && $rest < 20) {
            $out[] = self::TEENS[$rest - 10];
        } else {
            $t = intdiv($rest, 10);
            $o = $rest % 10;
            if ($t) {
                $out[] = self::TENS[$t];
            }
            if ($o) {
                $out[] = ($feminine ? self::ONES_F : self::ONES_M)[$o];
            }
        }

        return $out;
    }
}
