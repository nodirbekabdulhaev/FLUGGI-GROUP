<?php

namespace App\Services\Smm;

/**
 * Подпись для Telegram без разметки (parse_mode не задаётся, текст уходит как есть).
 * Лимиты Bot API считаются в единицах UTF-16 (эмодзи вне BMP — 2): подпись к медиа — 1024, сообщение — 4096.
 * Длинная подпись: к медиа — первая часть до 1024 (по границе строки или слова), остальное — следующими сообщениями до 4096.
 */
final class TelegramCaption
{
    public const CAPTION_MAX = 1024;

    public const MESSAGE_MAX = 4096;

    /** Длина в единицах UTF-16, как считает Telegram. */
    public static function units(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * @return array{0:string, 1:list<string>} подпись к медиа и продолжения
     */
    public static function split(?string $text): array
    {
        $text = trim(str_replace("\r\n", "\n", (string) $text));
        [$caption, $rest] = self::cut($text, self::CAPTION_MAX);
        $more = [];
        while ($rest !== '') {
            [$part, $rest] = self::cut($rest, self::MESSAGE_MAX);
            $more[] = $part;
        }

        return [$caption, $more];
    }

    /**
     * Первая часть не длиннее $limit и остаток. Граница — перевод строки (если он не в первой половине),
     * иначе пробел, иначе ровно по лимиту (не разрывая символ).
     *
     * @return array{0:string, 1:string}
     */
    public static function cut(string $text, int $limit): array
    {
        if (self::units($text) <= $limit) {
            return [$text, ''];
        }
        $chars = mb_str_split($text);
        $units = 0;
        $n = 0;
        foreach ($chars as $c) {
            $u = mb_ord($c) > 0xFFFF ? 2 : 1;
            if ($units + $u > $limit) {
                break;
            }
            $units += $u;
            $n++;
        }
        $prefix = implode('', array_slice($chars, 0, $n));
        $at = null;
        $line = mb_strrpos($prefix, "\n");
        if ($line !== false && $line >= intdiv($n, 2)) {
            $at = $line;
        } elseif (preg_match_all('/\s/u', $prefix, $m, PREG_OFFSET_CAPTURE) && ($last = end($m[0])) && $last[1] > 0) {
            $at = mb_strlen(substr($prefix, 0, $last[1]));
        } elseif ($line !== false && $line > 0) {
            $at = $line;
        }
        if ($at === null) {
            return [$prefix, ltrim(implode('', array_slice($chars, $n)))];
        }

        return [rtrim(mb_substr($text, 0, $at)), ltrim(mb_substr($text, $at + 1))];
    }
}
