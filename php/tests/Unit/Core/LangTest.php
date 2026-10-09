<?php

namespace Tests\Unit\Core;

use MessageFormatter;
use PHPUnit\Framework\TestCase;

/** Узбекский и русский переводы полные и одинаковые по ключам и подстановкам. */
class LangTest extends TestCase
{
    public function test_uz_and_ru_have_same_keys_and_placeholders(): void
    {
        $dir = dirname(__DIR__, 3).'/lang';
        $problems = [];
        foreach (glob("$dir/ru/*.json") as $ruFile) {
            $group = basename($ruFile, '.json');
            $uzFile = "$dir/uz/$group.json";
            if (! is_file($uzFile)) {
                $problems[] = "нет uz/$group.json";

                continue;
            }
            $ru = $this->flatten(json_decode(file_get_contents($ruFile), true));
            $uz = $this->flatten(json_decode(file_get_contents($uzFile), true));
            foreach (array_diff_key($ru, $uz) as $key => $_) {
                $problems[] = "uz: нет $group.$key";
            }
            foreach (array_diff_key($uz, $ru) as $key => $_) {
                $problems[] = "ru: нет $group.$key";
            }
            foreach (array_intersect_key($ru, $uz) as $key => $text) {
                if ($this->vars($text) !== $this->vars($uz[$key])) {
                    $problems[] = "подстановки различаются: $group.$key";
                }
                if (class_exists(MessageFormatter::class) && str_contains($uz[$key], '{') && MessageFormatter::create('uz_Latn', $uz[$key]) === null) {
                    $problems[] = "ошибка ICU: uz $group.$key";
                }
            }
        }
        $this->assertSame([], $problems);
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
