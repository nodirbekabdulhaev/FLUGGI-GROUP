<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Support\Js;

/**
 * Lead scoring (ТЗ §10). Чистая функция: факторы → 0–100 и уровень.
 * Каждый фактор нормирован в 0..1, итог — взвешенное среднее по известным факторам
 * (неизвестный фактор не штрафует и не завышает оценку).
 *
 * Вход computeLeadScore:
 *  - budgetUzs?: ?float — бюджет в UZS;
 *  - serviceMinPriceUzs?: ?float — минимальная цена выбранной услуги в UZS (если задана в каталоге);
 *  - hasService: bool;
 *  - priority: 'LOW'|'MEDIUM'|'HIGH'|'URGENT';
 *  - daysToDesiredDate?: ?float — сколько дней до желаемой даты старта (отрицательное — уже прошла);
 *  - companySize?: ?'SOLO'|'SMALL'|'MEDIUM'|'LARGE';
 *  - interest?: ?float — 1–5;
 *  - stageIndex: int — индекс этапа воронки лида: 0 — новый … 4 — встреча проведена;
 *  - stageCount: int.
 *
 * @phpstan-type LeadScoreInput array{budgetUzs?: int|float|null, serviceMinPriceUzs?: int|float|null, hasService: bool, priority: string, daysToDesiredDate?: int|float|null, companySize?: string|null, interest?: int|float|null, stageIndex: int|float, stageCount: int|float}
 * @phpstan-type LeadScoreResult array{score: int, level: string, factors: array<string, float>}
 */
final class LeadScore
{
    public const SCORE_WEIGHTS = [
        'budget' => 25,
        'urgency' => 15,
        'serviceFit' => 10,
        'companySize' => 10,
        'interest' => 20,
        'stage' => 20,
    ];

    private const PRIORITY_URGENCY = [
        'LOW' => 0.2,
        'MEDIUM' => 0.5,
        'HIGH' => 0.8,
        'URGENT' => 1.0,
    ];

    private const SIZE = ['SOLO' => 0.25, 'SMALL' => 0.5, 'MEDIUM' => 0.8, 'LARGE' => 1.0];

    public static function scoreLevel(int|float $score): string
    {
        if ($score > 80) {
            return 'HOT';
        }
        if ($score > 60) {
            return 'HIGH';
        }
        if ($score > 30) {
            return 'MEDIUM';
        }

        return 'LOW';
    }

    /**
     * @param  LeadScoreInput  $input
     * @return LeadScoreResult
     */
    public static function compute(array $input): array
    {
        $factors = [];

        $budget = $input['budgetUzs'] ?? null;
        if ($budget !== null && $budget > 0) {
            // Бюджет относительно минимальной цены услуги; без каталожной цены — шкала до 50 млн UZS.
            $min = $input['serviceMinPriceUzs'] ?? null;
            $ref = $min && $min > 0 ? $min * 2 : 50_000_000;
            $factors['budget'] = self::clamp01($budget / $ref);
        }

        $urgency = self::PRIORITY_URGENCY[$input['priority']];
        $days = $input['daysToDesiredDate'] ?? null;
        if ($days !== null) {
            $byDate = match (true) {
                $days <= 14 => 1.0,
                $days <= 45 => 0.7,
                $days <= 90 => 0.4,
                default => 0.2,
            };
            $urgency = max($urgency, $byDate);
        }
        $factors['urgency'] = (float) $urgency;

        $factors['serviceFit'] = $input['hasService'] ? 1.0 : 0.3;
        if (! empty($input['companySize'])) {
            $factors['companySize'] = self::SIZE[$input['companySize']];
        }
        if (($input['interest'] ?? null) !== null) {
            $factors['interest'] = self::clamp01(($input['interest'] - 1) / 4);
        }
        $factors['stage'] = $input['stageCount'] > 1
            ? self::clamp01($input['stageIndex'] / ($input['stageCount'] - 1))
            : 0.0;

        $weighted = 0.0;
        $weights = 0;
        foreach ($factors as $key => $value) {
            $w = self::SCORE_WEIGHTS[$key];
            $weighted += $w * $value;
            $weights += $w;
        }
        $score = $weights > 0 ? Js::round(($weighted / $weights) * 100) : 0;

        return ['score' => $score, 'level' => self::scoreLevel($score), 'factors' => $factors];
    }

    private static function clamp01(int|float $v): float
    {
        return (float) min(1, max(0, $v));
    }
}
