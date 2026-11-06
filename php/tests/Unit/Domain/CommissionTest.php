<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Commission;

final class CommissionTest extends DomainTestCase
{
    private const COND = [
        'any' => [
            ['metric' => 'avg_check_usd', 'op' => '>', 'value' => 3000],
            ['metric' => 'orders_count', 'op' => '>', 'value' => 15],
        ],
    ];

    private const RULES = [
        ['id' => 'base', 'calcType' => 'PERCENT_OF_PAYMENT', 'value' => 10, 'conditions' => null, 'priority' => 0, 'userId' => null],
        [
            'id' => 'bonus', 'calcType' => 'PERCENT_OF_PAYMENT', 'value' => 15, 'priority' => 10, 'userId' => null,
            'conditions' => ['any' => [['metric' => 'orders_count', 'op' => '>', 'value' => 15]]],
        ],
        ['id' => 'personal', 'calcType' => 'PERCENT_OF_PAYMENT', 'value' => 12, 'conditions' => null, 'priority' => 0, 'userId' => 'u2'],
    ];

    public function test_dsl_avg_check_or_orders_count(): void
    {
        self::assertTrue(Commission::evaluateCondition(self::COND, ['avg_check_usd' => 3500, 'orders_count' => 2]));
        self::assertTrue(Commission::evaluateCondition(self::COND, ['avg_check_usd' => 1000, 'orders_count' => 16]));
        self::assertFalse(Commission::evaluateCondition(self::COND, ['avg_check_usd' => 1000, 'orders_count' => 15]));
        self::assertTrue(Commission::evaluateCondition(null, []));
    }

    public function test_nested_groups(): void
    {
        $c = ['all' => [['metric' => 'orders_count', 'op' => '>=', 'value' => 5], self::COND]];
        self::assertTrue(Commission::evaluateCondition($c, ['orders_count' => 5, 'avg_check_usd' => 4000]));
        self::assertFalse(Commission::evaluateCondition($c, ['orders_count' => 4, 'avg_check_usd' => 4000]));
    }

    public function test_dsl_edge_cases_like_js(): void
    {
        self::assertTrue(Commission::evaluateCondition(['all' => []], []));
        self::assertFalse(Commission::evaluateCondition(['any' => []], []));
        self::assertTrue(Commission::evaluateCondition([], []));
        // Неизвестная/отсутствующая метрика = 0; 5 === 5.0
        self::assertTrue(Commission::evaluateCondition(['metric' => 'revenue_uzs', 'op' => '=', 'value' => 0], []));
        self::assertTrue(Commission::evaluateCondition(['metric' => 'orders_count', 'op' => '=', 'value' => 5.0], ['orders_count' => 5]));
        self::assertFalse(Commission::evaluateCondition(['metric' => 'x', 'op' => '!=', 'value' => 0], []));
        self::assertTrue(Commission::evaluateCondition(['metric' => 'revenue_usd', 'op' => '<=', 'value' => 10], ['revenue_usd' => 10]));
        self::assertFalse(Commission::evaluateCondition(['metric' => 'revenue_usd', 'op' => '<', 'value' => 10], ['revenue_usd' => 10]));
    }

    public function test_pick_rule_highest_priority(): void
    {
        self::assertSame('base', Commission::pickRule(self::RULES, ['orders_count' => 3], 'u1')['id'] ?? null);
        self::assertSame('bonus', Commission::pickRule(self::RULES, ['orders_count' => 20], 'u1')['id'] ?? null);
    }

    public function test_personal_rule_beats_general_on_equal_priority(): void
    {
        self::assertSame('personal', Commission::pickRule(self::RULES, ['orders_count' => 3], 'u2')['id'] ?? null);
        self::assertNull(Commission::pickRule([], [], 'u1'));
    }

    public function test_ten_percent_of_payment(): void
    {
        self::assertDec('900000', Commission::calc(['calcType' => 'PERCENT_OF_PAYMENT', 'value' => 10], '9000000', ['firstPaymentOfDeal' => true])['amount']);
    }

    public function test_refund_gives_negative_commission(): void
    {
        self::assertDec('-100000', Commission::calc(['calcType' => 'PERCENT_OF_PAYMENT', 'value' => 10], '-1000000', ['firstPaymentOfDeal' => false])['amount']);
    }

    public function test_fixed_only_on_first_payment(): void
    {
        self::assertDec('500000', Commission::calc(['calcType' => 'FIXED_PER_DEAL', 'value' => 500000], '3000000', ['firstPaymentOfDeal' => true])['amount']);
        self::assertDec('0', Commission::calc(['calcType' => 'FIXED_PER_DEAL', 'value' => 500000], '3000000', ['firstPaymentOfDeal' => false])['amount']);
        // Возврат по фиксированной — сторно.
        self::assertDec('-500000', Commission::calc(['calcType' => 'FIXED_PER_DEAL', 'value' => 500000], '-1', ['firstPaymentOfDeal' => false])['amount']);
    }

    public function test_percent_of_profit_uses_margin(): void
    {
        self::assertDec('450000', Commission::calc(['calcType' => 'PERCENT_OF_PROFIT', 'value' => 10], '9000000', ['firstPaymentOfDeal' => true, 'marginPct' => 50])['amount']);
        $r = Commission::calc(['calcType' => 'PERCENT_OF_PROFIT', 'value' => '7.5'], '1234567.89', ['firstPaymentOfDeal' => false, 'marginPct' => 33.33]);
        self::assertDec('30861.11', $r['amount']);
        self::assertDec('0.01', Commission::calc(['calcType' => 'PERCENT_OF_PAYMENT', 'value' => '2.5'], '0.3', ['firstPaymentOfDeal' => false])['amount']);
    }
}
