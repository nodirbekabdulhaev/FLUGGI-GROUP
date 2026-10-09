<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Support\Dec;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Движок комиссий (ТЗ §33–34). Правила — данные из БД; условие — ограниченный DSL:
 *   { "all" | "any": [ { "metric": "avg_check_usd", "op": ">", "value": 3000 }, … ] }
 * Вложенность групп допускается. Неизвестная метрика = 0.
 *
 * @phpstan-type ConditionLeaf array{metric: string, op: string, value: int|float}
 * @phpstan-type Condition array<string, mixed>
 * @phpstan-type Metrics array<string, int|float>
 * @phpstan-type RuleInput array{id: string, calcType: string, value: string|int|float, conditions: ?array<string, mixed>, priority: int|float, userId: ?string}
 * @phpstan-type CommissionCalc array{base: BigDecimal, rate: BigDecimal, amount: BigDecimal}
 */
final class Commission
{
    public const METRICS = [
        'avg_check_usd',
        'avg_check_uzs',
        'orders_count',
        'revenue_uzs',
        'revenue_usd',
    ];

    public const PERCENT_OF_PAYMENT = 'PERCENT_OF_PAYMENT';

    public const PERCENT_OF_PROFIT = 'PERCENT_OF_PROFIT';

    public const FIXED_PER_DEAL = 'FIXED_PER_DEAL';

    /**
     * @param  Condition|null  $cond
     * @param  Metrics  $metrics
     */
    public static function evaluateCondition(?array $cond, array $metrics): bool
    {
        if ($cond === null) {
            return true;
        }
        if (array_key_exists('metric', $cond)) {
            $v = (float) ($metrics[$cond['metric']] ?? 0);
            $value = (float) $cond['value'];

            return match ($cond['op'] ?? null) {
                '>' => $v > $value,
                '>=' => $v >= $value,
                '<' => $v < $value,
                '<=' => $v <= $value,
                '=' => $v === $value,
                default => false,
            };
        }
        // Пустой список — как в JS: all([]) = true, any([]) = false.
        if (isset($cond['all'])) {
            foreach ($cond['all'] as $c) {
                if (! self::evaluateCondition($c, $metrics)) {
                    return false;
                }
            }

            return true;
        }
        if (isset($cond['any'])) {
            foreach ($cond['any'] as $c) {
                if (self::evaluateCondition($c, $metrics)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Выбор правила: подходящее по условиям с наибольшим приоритетом (персональное — раньше общего).
     * userId правила — персонально для сотрудника, приоритетнее общего при равном priority.
     *
     * @template T of RuleInput
     *
     * @param  list<T>  $rules
     * @param  Metrics  $metrics
     * @return T|null
     */
    public static function pickRule(array $rules, array $metrics, string $userId): ?array
    {
        $applicable = array_values(array_filter(
            $rules,
            fn (array $r) => ($r['userId'] === null || $r['userId'] === $userId)
                && self::evaluateCondition($r['conditions'], $metrics),
        ));
        // usort стабилен (PHP 8), как Array#sort.
        usort(
            $applicable,
            fn (array $a, array $b) => ($b['priority'] <=> $a['priority'])
                ?: (int) ($b['userId'] !== null) - (int) ($a['userId'] !== null),
        );

        return $applicable[0] ?? null;
    }

    /**
     * Сумма комиссии с одного платежа.
     * PERCENT_OF_PAYMENT — % от суммы платежа; PERCENT_OF_PROFIT — % от доли прибыли в платеже
     * (payment × маржа проекта); FIXED_PER_DEAL — фиксированная сумма, только с первого платежа сделки.
     * Возврат (отрицательная сумма) даёт отрицательную комиссию — сторно.
     *
     * @param  array{calcType: string, value: string|int|float|BigDecimal}  $rule
     * @param  array{firstPaymentOfDeal: bool, marginPct?: int|float|string|null}  $opts
     * @return CommissionCalc
     */
    public static function calc(array $rule, BigDecimal|string|int|float $paymentUzs, array $opts): array
    {
        $payment = Dec::of($paymentUzs);
        $value = Dec::of($rule['value']);

        switch ($rule['calcType']) {
            case self::PERCENT_OF_PAYMENT:
                return [
                    'base' => $payment,
                    'rate' => $value,
                    'amount' => Dec::dp(Dec::div(Dec::mul($payment, $value), 100), 2),
                ];
            case self::PERCENT_OF_PROFIT:
                $margin = Dec::of($opts['marginPct'] ?? 0);
                $base = Dec::dp(Dec::div(Dec::mul($payment, $margin), 100), 2);

                return [
                    'base' => $base,
                    'rate' => $value,
                    'amount' => Dec::dp(Dec::div(Dec::mul($base, $value), 100), 2),
                ];
            case self::FIXED_PER_DEAL:
                $negative = Dec::isNegative($paymentUzs);
                $amount = $opts['firstPaymentOfDeal'] || $negative
                    ? Dec::mul($value, $negative ? -1 : 1)
                    : BigDecimal::zero();

                return ['base' => $payment, 'rate' => $value, 'amount' => $amount];
        }

        throw new InvalidArgumentException("Unknown calcType: {$rule['calcType']}");
    }
}
