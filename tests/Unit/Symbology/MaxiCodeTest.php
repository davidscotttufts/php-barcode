<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Renderer\RenderOptions;
use PhpBarcode\Symbol\HexagonSymbol;
use PhpBarcode\Symbology\MaxiCode;
use PHPUnit\Framework\TestCase;

final class MaxiCodeTest extends TestCase
{
    public function testFixedSize(): void
    {
        $symbol = (new MaxiCode())->encode('Hello MaxiCode');

        self::assertInstanceOf(HexagonSymbol::class, $symbol);
        $grid = $symbol->toGrid();
        self::assertCount(33, $grid);
        self::assertSame(30, strlen($grid[0]));
    }

    public function testOrientationMarksAreDark(): void
    {
        $grid = (new MaxiCode())->encode('A')->toGrid();

        foreach ([[0, 28], [0, 29], [9, 10], [9, 11], [10, 11], [15, 7], [16, 8], [16, 20], [17, 20], [22, 10], [23, 10], [22, 17], [23, 17]] as [$row, $column]) {
            self::assertSame('1', $grid[$row][$column], "row $row, column $column");
        }
    }

    public function testModeIsTheFirstCodeword(): void
    {
        // The mode occupies the low 4 bits of codeword 1, whose bits sit at sequence numbers 1-6.
        $mode4 = (new MaxiCode())->encode('A')->toGrid();
        $mode5 = (new MaxiCode(mode: 5))->encode('A')->toGrid();

        self::assertNotSame($mode4, $mode5);
    }

    public function testPrimaryMessageModeIsInferred(): void
    {
        self::assertNotSame(
            (new MaxiCode(postalCode: '152382802', countryCode: 840, serviceClass: 1))->encode('X')->toGrid(),
            (new MaxiCode(postalCode: 'B1050', countryCode: 56, serviceClass: 1))->encode('X')->toGrid(),
        );
    }

    public function testRejectsTooMuchData(): void
    {
        $this->expectException(InvalidDataException::class);
        (new MaxiCode())->encode(str_repeat('a', 200));
    }

    public function testEnhancedModeHoldsLessThanStandard(): void
    {
        $data = str_repeat('ABCDEFGHIJ', 9); // 90 codewords

        self::assertTrue((new MaxiCode())->supports($data));
        self::assertFalse((new MaxiCode(mode: 5))->supports($data));
    }

    public function testDigitRunsAreCompacted(): void
    {
        // 138 digits fit thanks to Numeric Shift (6 codewords per 9 digits); 138 letters would not.
        self::assertTrue((new MaxiCode())->supports(str_repeat('1', 135)));
        self::assertFalse((new MaxiCode())->supports(str_repeat('A', 135)));
    }

    public function testRejectsInvalidConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MaxiCode(mode: 2);
    }

    public function testRejectsPostalCodeOutsideModes2And3(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MaxiCode(mode: 4, postalCode: '12345', countryCode: 840, serviceClass: 1);
    }

    public function testLayoutCoversTheSymbolAndFinder(): void
    {
        $symbol = (new MaxiCode())->encode('A');
        self::assertInstanceOf(HexagonSymbol::class, $symbol);
        $layout = $symbol->layout(new RenderOptions(moduleWidth: 10, quietZone: 0, showText: false));

        self::assertEqualsWithDelta(305.0, $layout->width, 0.001);
        self::assertEqualsWithDelta(10 * (HexagonSymbol::HEX_HEIGHT + 32 * HexagonSymbol::ROW_PITCH), $layout->height, 0.001);

        $finder = $symbol->finder();
        self::assertEqualsWithDelta(14.5, $finder['x'], 0.0001);
        self::assertCount(3, $finder['rings']);
        self::assertEqualsWithDelta(4.5, $finder['rings'][2]['outer'], 0.0001);
    }
}
