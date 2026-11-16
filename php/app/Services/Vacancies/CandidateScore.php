<?php

namespace App\Services\Vacancies;

/**
 * Оценка кандидата 0–100 по данным игровой формы сайта. Логика (веса можно менять тут):
 *  - психологические тесты 40 %: среднее по чертам (0–100), дисциплина и ответственность весят 1,3, остальные 1;
 *  - навыки 25 %: средний уровень (1–5) × поправка на число навыков (5 и больше — полный балл);
 *  - языки 15 %: лучший уровень + 8 за каждый следующий язык;
 *  - образование 10 %: высшая ступень (школа 40, колледж 60, курсы 55, бакалавр 80, магистр 95);
 *  - опыт 10 %: 5 лет и больше — полный балл, 20 за год.
 * Раздел, которого нет в анкете, не учитывается: веса остальных пересчитываются. Черта ниже 35 — «красный флаг».
 */
final class CandidateScore
{
    public const WEIGHTS = ['psych' => 40, 'skills' => 25, 'languages' => 15, 'education' => 10, 'experience' => 10];

    public const TRAITS = ['discipline' => 1.3, 'responsibility' => 1.3, 'stress_resistance' => 1.0, 'teamwork' => 1.0, 'learning' => 1.0, 'communication' => 1.0];

    private const LANG = ['native' => 100, 'c2' => 100, 'c1' => 90, 'b2' => 75, 'b1' => 60, 'a2' => 40, 'a1' => 25];

    private const EDU = ['school' => 40, 'college' => 60, 'courses' => 55, 'bachelor' => 80, 'university' => 80, 'master' => 95];

    public const RED_FLAG = 35;

    /** @return array{score:?int,level:?string,parts:array<string,array{score:int,weight:int}>,flags:list<string>} */
    public static function evaluate(array $profile, array $assessment): array
    {
        $parts = [];
        $flags = [];

        $traits = array_filter((array) ($assessment['traits'] ?? []), fn ($v) => is_numeric($v));
        if ($traits) {
            $sum = $w = 0.0;
            foreach ($traits as $k => $v) {
                $v = max(0, min(100, (float) $v));
                $weight = self::TRAITS[$k] ?? 1.0;
                $sum += $v * $weight;
                $w += $weight;
                if ($v < self::RED_FLAG) {
                    $flags[] = (string) $k;
                }
            }
            $parts['psych'] = (int) round($sum / $w);
        }

        $skills = array_filter((array) ($profile['skills'] ?? []), fn ($s) => is_array($s) && isset($s['level']) && is_numeric($s['level']));
        if ($skills) {
            $avg = array_sum(array_map(fn ($s) => max(1, min(5, (float) $s['level'])), $skills)) / count($skills);
            $parts['skills'] = (int) round($avg / 5 * 100 * (0.6 + 0.4 * min(1, count($skills) / 5)));
        }

        $langs = array_map(fn ($l) => self::LANG[strtolower((string) ($l['level'] ?? ''))] ?? null, array_filter((array) ($profile['languages'] ?? []), 'is_array'));
        $langs = array_filter($langs, fn ($v) => $v !== null);
        if ($langs) {
            $parts['languages'] = min(100, max($langs) + 8 * (count($langs) - 1));
        }

        $edu = array_map(fn ($e) => self::EDU[strtolower((string) ($e['level'] ?? ''))] ?? 50, array_filter((array) ($profile['education'] ?? []), 'is_array'));
        if ($edu) {
            $parts['education'] = max($edu);
        }

        $years = isset($profile['experience_years']) && is_numeric($profile['experience_years']) ? (float) $profile['experience_years']
            : (($exp = array_filter((array) ($profile['experience'] ?? []), 'is_array')) ? array_sum(array_map(fn ($e) => max(0, (float) ($e['years'] ?? 0)), $exp)) : null);
        if ($years !== null) {
            $parts['experience'] = (int) min(100, round($years * 20));
        }

        if (! $parts) {
            return ['score' => null, 'level' => null, 'parts' => [], 'flags' => []];
        }
        $total = array_sum(array_intersect_key(self::WEIGHTS, $parts));
        $score = (int) round(array_sum(array_map(fn ($k, $v) => $v * self::WEIGHTS[$k], array_keys($parts), $parts)) / $total);
        $out = [];
        foreach ($parts as $k => $v) {
            $out[$k] = ['score' => $v, 'weight' => self::WEIGHTS[$k]];
        }

        return ['score' => $score, 'level' => self::level($score), 'parts' => $out, 'flags' => $flags];
    }

    public static function level(int $score): string
    {
        return $score >= 80 ? 'excellent' : ($score >= 65 ? 'strong' : ($score >= 50 ? 'medium' : 'weak'));
    }
}
