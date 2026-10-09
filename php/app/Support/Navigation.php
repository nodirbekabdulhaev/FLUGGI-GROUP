<?php

namespace App\Support;

use App\Auth\Access;

/** Меню, отфильтрованное по правам пользователя. */
final class Navigation
{
    public static function for(Access $access): array
    {
        $visible = fn (array $item) => empty($item['any']) || $access->canAny($item['any']);
        $out = [];
        foreach (config('navigation') as $section) {
            if (isset($section['children'])) {
                $children = array_values(array_filter($section['children'], $visible));
                if ($children) {
                    $out[] = [...$section, 'children' => $children];
                }
            } elseif ($visible($section)) {
                $out[] = $section;
            }
        }

        return $out;
    }

    public static function isActive(string $href): bool
    {
        $path = '/'.ltrim(request()->path(), '/');

        return $path === $href || str_starts_with($path, rtrim($href, '/').'/');
    }
}
