<?php

namespace Tests\Unit\Core;

use MessageFormatter;
use PHPUnit\Framework\TestCase;

/** Переводы (узбекский, английский) полные и совпадают с русскими по ключам и подстановкам. */
class LangTest extends TestCase
{
    public function test_translations_have_same_keys_and_placeholders_as_russian(): void
    {
        foreach (['uz' => 'uz_Latn', 'en' => 'en_US'] as $locale => $icu) {
            $this->assertSame([], $this->problems($locale, $icu), "lang/$locale");
        }
    }

    private function problems(string $locale, string $icu): array
    {
        $dir = dirname(__DIR__, 3).'/lang';
        $problems = [];
        foreach (glob("$dir/ru/*.json") as $ruFile) {
            $group = basename($ruFile, '.json');
            $uzFile = "$dir/$locale/$group.json";
            if (! is_file($uzFile)) {
                $problems[] = "нет $locale/$group.json";

                continue;
            }
            $ru = $this->flatten(json_decode(file_get_contents($ruFile), true));
            $uz = $this->flatten(json_decode(file_get_contents($uzFile), true));
            foreach (array_diff_key($ru, $uz) as $key => $_) {
                $problems[] = "$locale: нет $group.$key";
            }
            foreach (array_diff_key($uz, $ru) as $key => $_) {
                $problems[] = "ru: нет $group.$key";
            }
            foreach (array_intersect_key($ru, $uz) as $key => $text) {
                if ($this->vars($text) !== $this->vars($uz[$key])) {
                    $problems[] = "подстановки различаются: $group.$key";
                }
                if (class_exists(MessageFormatter::class) && str_contains($uz[$key], '{') && MessageFormatter::create($icu, $uz[$key]) === null) {
                    $problems[] = "ошибка ICU: $locale $group.$key";
                }
            }
        }

        return $problems;
    }

    private function flatten(array $a, string $prefix = ''): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            is_array($v) ? $out += $this->flatten($v, "$prefix$k.") : $out["$prefix$k"] = (string) $v;
        }

        return $out;
    }

    /** Имена подстановок ({name}, {count, plural …}) без текста вариантов. */
    private function vars(string $s): array
    {
        preg_match_all('/\{(\w+)(?=[,}])/', $s, $m);
        $vars = array_values(array_unique($m[1]));
        sort($vars);

        return $vars;
    }
}
