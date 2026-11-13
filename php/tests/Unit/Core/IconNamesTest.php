<?php

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Каждое имя значка из шаблонов (<x-icon name>, :name с вариантами, <x-empty icon>, 'icon' => …, наборы *Icon(s))
 * и из меню (config/navigation.php) есть в resources/views/components/icon.blade.php —
 * иначе вместо значка молча показывался бы запасной «info».
 */
class IconNamesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    public function test_every_used_icon_name_exists_in_icon_component(): void
    {
        $known = $this->known();
        $this->assertGreaterThan(40, count($known));

        $used = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root.'/resources/views'));
        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php') || str_ends_with($file->getPathname(), 'components/icon.blade.php')) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($this->root) + 1);
            foreach ($this->namesIn((string) file_get_contents($file->getPathname())) as $name) {
                $used[$name][] = $rel;
            }
        }
        $nav = (string) file_get_contents($this->root.'/config/navigation.php');
        preg_match_all("/'icon'\s*=>\s*'([a-z0-9-]+)'/", $nav, $m);
        foreach ($m[1] as $name) {
            $used[$name][] = 'config/navigation.php';
        }
        // Значки разделов меню — обязательно у каждого раздела верхнего уровня
        $this->assertGreaterThanOrEqual(15, count($m[1]));

        $missing = array_diff_key($used, array_flip($known));
        $this->assertSame([], array_map(fn ($f) => array_values(array_unique($f)), $missing), 'Нет значка в components/icon.blade.php');
    }

    public function test_icon_set_is_consistent_line_icons(): void
    {
        $src = (string) file_get_contents($this->root.'/resources/views/components/icon.blade.php');
        $this->assertStringContainsString('viewBox="0 0 24 24"', $src);
        $this->assertStringContainsString('stroke="currentColor"', $src);
        $this->assertStringContainsString('stroke-linecap="round"', $src);
        $this->assertStringContainsString('stroke-linejoin="round"', $src);
        $this->assertStringContainsString('fill="none"', $src);
        // Имена не повторяются (повтор в массиве PHP молча заменяет первый контур)
        preg_match_all("/^\s*'([a-z0-9-]+)' => '</m", $src, $m);
        $this->assertSame(array_unique($m[1]), $m[1]);
        // Контуры — без своей заливки и цвета: всё задаёт currentColor
        $this->assertDoesNotMatchRegularExpression('/(fill|stroke)="#/', $src);
    }

    /** @return list<string> */
    private function known(): array
    {
        $src = (string) file_get_contents($this->root.'/resources/views/components/icon.blade.php');
        preg_match_all("/^\s*'([a-z0-9-]+)' => '</m", $src, $m);

        return $m[1];
    }

    /** @return list<string> */
    private function namesIn(string $blade): array
    {
        $names = [];
        // <x-icon name="plus"> и <x-icon :name="$a ? 'check' : 'clock'"> (все строки в кавычках внутри выражения)
        preg_match_all('/<x-icon\b[^>]*?\s(:?)name="([^"]*)"/s', $blade, $m, PREG_SET_ORDER);
        foreach ($m as [, $bound, $value]) {
            if ($bound === '') {
                $names[] = $value;
            } else {
                // строки-значки, а не ключи массивов ($section['icon'])
                preg_match_all("/(?<!\\[)'([a-z0-9-]+)'(?!\\])/", $value, $q);
                array_push($names, ...$q[1]);
            }
        }
        // <x-empty icon="file">
        preg_match_all('/<x-[a-z.-]+\b[^>]*?\sicon="([a-z0-9-]+)"/s', $blade, $m);
        array_push($names, ...$m[1]);
        // 'icon' => 'trending' (плитки, пункты) и умолчание @props(['icon' => 'info'])
        preg_match_all("/'icon'\s*=>\s*'([a-z0-9-]+)'/", $blade, $m);
        array_push($names, ...$m[1]);
        // Наборы значков: $kindIcon = ['BANK' => 'briefcase', …], $kindIcons = [...]
        preg_match_all('/\$\w*[iI]cons?\s*=\s*\[([^\]]*)\]/', $blade, $m);
        foreach ($m[1] as $list) {
            preg_match_all("/=>\s*'([a-z0-9-]+)'/", $list, $q);
            array_push($names, ...$q[1]);
        }
        // Перебор пар [значок, подпись]: @foreach (['kanban' => ['kanban', …], 'list' => ['rows', …]] as $v => [$icon, $label])
        preg_match_all('/^.*\[\$icon\b.*$/m', $blade, $m);
        foreach ($m[0] as $line) {
            preg_match_all("/=>\s*\['([a-z0-9-]+)',/", $line, $q);
            array_push($names, ...$q[1]);
        }

        return array_values(array_unique($names));
    }
}
