<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Symbology\Postal;

use PhpBarcode\Symbology\Postal\LargeInteger;
use PHPUnit\Framework\TestCase;

final class LargeIntegerTest extends TestCase
{
    public function testArithmeticBeyond64Bits(): void
    {
        // 2^100
        $value = LargeInteger::fromDecimal('1267650600228229401496703205376');

        self::assertSame('1' . str_repeat('0', 100), $value->toBits(101));

        // 2^100 ≡ 2 (mod 7), so 2^100 + 5 is divisible by 7.
        $value->add(5);
        self::assertSame(0, $value->divideBy(7));
    }

    public function testDivisionMatchesDecimalLongDivision(): void
    {
        $value = LargeInteger::fromDecimal('123456789012345678901234567890');

        self::assertSame(890, $value->divideBy(1000));
        self::assertSame(567, $value->divideBy(1000));
        self::assertSame(234, $value->divideBy(1000));
    }

    public function testMultiplyAddAndToInt(): void
    {
        $value = new LargeInteger(12345);
        $value->multiplyAdd(100000000, 99);

        self::assertSame(1234500000099, $value->toInt());
    }
}
