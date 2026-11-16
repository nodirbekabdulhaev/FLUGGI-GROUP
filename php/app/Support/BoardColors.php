<?php

namespace App\Support;

use App\Exceptions\BusinessRule;
use App\Services\Projects\ProjectAccess;
use App\Services\Smm\SmmIdeaService;
use Illuminate\Support\Facades\DB;

/**
 * Цвета колонок Kanban. Этапы воронки хранят цвет в deal_stages.color (Настройки → Справочники),
 * статусы идей SMM и задач проектов — здесь, в настройке boardColors. Колонка доски окрашивается
 * в светлый оттенок цвета (resources/css/app.css, .kanban-col).
 */
final class BoardColors
{
    public const KEY = 'boardColors';

    /** Готовые цвета в выборе (пастельные, достаточно насыщенные для точки и оттенка колонки). */
    public const PALETTE = [
        '#94a3b8', '#60a5fa', '#38bdf8', '#2dd4bf', '#4ade80', '#a3e635',
        '#fbbf24', '#fb923c', '#fb7185', '#f472b6', '#a78bfa', '#818cf8',
    ];

    public const DEFAULTS = [
        'smm' => [
            'IDEA' => '#a78bfa', 'APPROVED' => '#60a5fa', 'SHOOTING' => '#fb923c',
            'EDITING' => '#fbbf24', 'SCHEDULED' => '#38bdf8', 'PUBLISHED' => '#4ade80',
        ],
        'tasks' => ['TODO' => '#94a3b8', 'IN_PROGRESS' => '#60a5fa', 'REVIEW' => '#fbbf24', 'DONE' => '#4ade80'],
    ];

    public const HEX = '/^#[0-9a-fA-F]{6}$/';

    /** Статусы каждой доски — в порядке колонок. */
    public static function statuses(): array
    {
        return ['smm' => SmmIdeaService::KANBAN, 'tasks' => ProjectAccess::KANBAN];
    }

    /** @return array<string,string> статус → #rrggbb */
    public static function for(string $board): array
    {
        $saved = Settings::get(self::KEY)[$board] ?? [];
        $out = [];
        foreach (self::statuses()[$board] ?? [] as $status) {
            $c = $saved[$status] ?? null;
            $out[$status] = is_string($c) && preg_match(self::HEX, $c) ? strtolower($c) : (self::DEFAULTS[$board][$status] ?? '#94a3b8');
        }

        return $out;
    }

    /** Все доски: [board => [status => color]]. */
    public static function all(): array
    {
        return collect(self::statuses())->keys()->mapWithKeys(fn ($b) => [$b => self::for($b)])->all();
    }

    /**
     * Сохраняет цвета одной доски (только известные статусы, только #rrggbb) и пишет аудит изменений.
     *
     * @param  array<string,string>  $colors
     */
    public static function save(string $board, array $colors): array
    {
        $statuses = self::statuses()[$board] ?? null;
        if ($statuses === null) {
            abort(404);
        }
        $before = self::for($board);
        $after = $before;
        foreach ($colors as $status => $color) {
            if (! in_array($status, $statuses, true)) {
                continue;
            }
            if (! is_string($color) || ! preg_match(self::HEX, $color)) {
                throw new BusinessRule(t('boardColors.errors.hex'), ["colors.$status" => t('boardColors.errors.hex')]);
            }
            $after[$status] = strtolower($color);
        }
        $changes = [];
        foreach ($after as $status => $color) {
            if ($before[$status] !== $color) {
                $changes["$board.$status"] = ['old' => $before[$status], 'new' => $color];
            }
        }
        if ($changes) {
            DB::transaction(function () use ($board, $after, $changes) {
                $value = Settings::get(self::KEY);
                $value[$board] = $after;
                Settings::save(self::KEY, $value);
                Audit::log('settings.board_colors', 'setting', null, $changes);
            });
        }

        return $after;
    }
}
