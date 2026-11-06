<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Words;

final class WordsTest extends DomainTestCase
{
    public function test_plural_forms(): void
    {
        $f = ['сум', 'сума', 'сумов'];
        self::assertSame('сум', Words::plural(1, $f));
        self::assertSame('сума', Words::plural(3, $f));
        self::assertSame('сумов', Words::plural(11, $f));
        self::assertSame('сум', Words::plural(21, $f));
        self::assertSame('сумов', Words::plural(112, $f));
    }

    public function test_numbers(): void
    {
        self::assertSame('ноль', Words::numberInWords(0));
        self::assertSame('две тысячи один', Words::numberInWords(2001));
        self::assertSame('один миллион', Words::numberInWords(1_000_000));
        self::assertSame('семь миллионов пятьсот двенадцать тысяч девятнадцать', Words::numberInWords(7_512_019));
        self::assertSame('двадцать одна тысяча', Words::numberInWords(21_000));
        self::assertSame(
            'один триллион двести тридцать четыре миллиарда пятьсот шестьдесят семь миллионов восемьсот девяносто одна тысяча одиннадцать',
            Words::numberInWords(1_234_567_891_011),
        );
        self::assertSame('двенадцать', Words::numberInWords(-12.9));
        self::assertSame('одна', Words::numberInWords(1, true));
        // Больше триллионов шкала не идёт — как в TS.
        self::assertSame('', Words::numberInWords(1_000_000_000_000_000));
    }

    public function test_amounts_in_contract_currency(): void
    {
        self::assertSame('Семь миллионов пятьсот тысяч сумов 00 тийинов', Words::amountInWords('7500000.00', 'UZS'));
        self::assertSame('Шестьсот пятьдесят долларов США 00 центов', Words::amountInWords(650, 'USD'));
        self::assertSame('Один доллар США 21 цент', Words::amountInWords('1.21', 'USD'));
    }

    public function test_amount_rounding_follows_js_to_fixed(): void
    {
        // (1.005).toFixed(2) === '1.00', (0.125).toFixed(2) === '0.13'
        self::assertSame('Один сум 00 тийинов', Words::amountInWords('1.005', 'UZS'));
        self::assertSame('Ноль долларов США 13 центов', Words::amountInWords(0.125, 'USD'));
        self::assertSame('Двадцать один сум 50 тийинов', Words::amountInWords(-21.5, 'UZS'));
        self::assertSame('Одна тысяча один EUR 00', Words::amountInWords('1001', 'EUR'));
        self::assertSame('Два миллиарда сумов 99 тийинов', Words::amountInWords(2_000_000_000.99, 'UZS'));
    }
}
