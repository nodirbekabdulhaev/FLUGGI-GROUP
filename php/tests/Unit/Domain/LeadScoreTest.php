<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\LeadScore;

final class LeadScoreTest extends DomainTestCase
{
    public function test_levels_per_spec(): void
    {
        self::assertSame('LOW', LeadScore::scoreLevel(30));
        self::assertSame('MEDIUM', LeadScore::scoreLevel(31));
        self::assertSame('HIGH', LeadScore::scoreLevel(61));
        self::assertSame('HOT', LeadScore::scoreLevel(81));
    }

    public function test_hot_lead(): void
    {
        $r = LeadScore::compute([
            'budgetUzs' => 100_000_000,
            'hasService' => true,
            'priority' => 'URGENT',
            'daysToDesiredDate' => 7,
            'companySize' => 'LARGE',
            'interest' => 5,
            'stageIndex' => 4,
            'stageCount' => 5,
        ]);
        self::assertSame(100, $r['score']);
        self::assertSame('HOT', $r['level']);
    }

    public function test_cold_lead_without_data(): void
    {
        $r = LeadScore::compute(['hasService' => false, 'priority' => 'LOW', 'stageIndex' => 0, 'stageCount' => 5]);
        self::assertSame('LOW', $r['level']);
        self::assertSame(13, $r['score']);
        self::assertSame(['urgency' => 0.2, 'serviceFit' => 0.3, 'stage' => 0.0], $r['factors']);
    }

    public function test_budget_relative_to_service_price(): void
    {
        $base = ['hasService' => true, 'priority' => 'MEDIUM', 'stageIndex' => 0, 'stageCount' => 5];
        $low = LeadScore::compute($base + ['budgetUzs' => 1_000_000, 'serviceMinPriceUzs' => 10_000_000]);
        $high = LeadScore::compute($base + ['budgetUzs' => 20_000_000, 'serviceMinPriceUzs' => 10_000_000]);
        self::assertGreaterThan($low['score'], $high['score']);
        self::assertSame(27, $low['score']);
        self::assertSame(61, $high['score']);
        self::assertSame(0.05, $low['factors']['budget']);
    }

    public function test_mixed_factors_match_ts(): void
    {
        $r = LeadScore::compute([
            'hasService' => true, 'priority' => 'HIGH', 'budgetUzs' => 25_000_000, 'daysToDesiredDate' => 60,
            'companySize' => 'SMALL', 'interest' => 3, 'stageIndex' => 2, 'stageCount' => 5,
        ]);
        self::assertSame(60, $r['score']);
        self::assertSame('MEDIUM', $r['level']);
        self::assertSame(
            ['budget' => 0.5, 'urgency' => 0.8, 'serviceFit' => 1.0, 'companySize' => 0.5, 'interest' => 0.5, 'stage' => 0.5],
            $r['factors'],
        );

        // Прошедшая дата — максимальная срочность; один этап — stage = 0; interest < 1 обрезается до 0.
        $r = LeadScore::compute([
            'hasService' => false, 'priority' => 'LOW', 'daysToDesiredDate' => -3, 'interest' => 0,
            'stageIndex' => 9, 'stageCount' => 1,
        ]);
        self::assertSame(28, $r['score']);
        self::assertSame(['urgency' => 1.0, 'serviceFit' => 0.3, 'interest' => 0.0, 'stage' => 0.0], $r['factors']);

        // Нулевой бюджет не учитывается; дальняя дата не снижает приоритет.
        $r = LeadScore::compute([
            'hasService' => true, 'priority' => 'MEDIUM', 'budgetUzs' => 0, 'daysToDesiredDate' => 120,
            'interest' => 2, 'stageIndex' => 1, 'stageCount' => 4,
        ]);
        self::assertSame(45, $r['score']);
        self::assertArrayNotHasKey('budget', $r['factors']);
        self::assertSame(0.5, $r['factors']['urgency']);

        $r = LeadScore::compute([
            'hasService' => false, 'priority' => 'URGENT', 'daysToDesiredDate' => 30, 'companySize' => 'SOLO',
            'interest' => 4, 'stageIndex' => 3, 'stageCount' => 7,
        ]);
        self::assertSame(61, $r['score']);
        self::assertSame('HIGH', $r['level']);
    }
}
