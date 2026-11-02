<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbology\QrCode\ErrorCorrectionLevel;
use PhpBarcode\Symbology\RectangularMicroQrCode;
use PHPUnit\Framework\TestCase;

final class RectangularMicroQrCodeTest extends TestCase
{
    /**
     * "123456" at level M (R11x27); identical to Zint's output and decoded by zxing-cpp.
     */
    public function testMatchesReferenceSymbol(): void
    {
        $expected = [
            '111111101010101010101010111',
            '100000101110001001110000101',
            '101110100111111001000000011',
            '101110100010001000001000100',
            '101110100000010011111110011',
            '100000100110000100001110110',
            '111111101101010110100111111',
            '000000001101100011101010001',
            '110010111010010000110110101',
            '101000110001101001001010001',
            '111010101010101010101011111',
        ];

        $symbol = (new RectangularMicroQrCode())->encode('123456');

        self::assertSame('rMQR Code R11x27', $symbol->symbology());
        self::assertSame($expected, $symbol->toGrid());
    }

    public function testListsAllThirtyTwoSizes(): void
    {
        $sizes = RectangularMicroQrCode::sizes();

        self::assertCount(32, $sizes);
        self::assertSame('R7x43', $sizes[0]);
        self::assertSame('R17x139', $sizes[31]);
    }

    public function testForcedSizeAndHeight(): void
    {
        $forced = (new RectangularMicroQrCode(size: 'R13x77'))->encode('A')->toGrid();
        self::assertCount(13, $forced);
        self::assertSame(77, strlen($forced[0]));

        self::assertCount(7, (new RectangularMicroQrCode(height: 7))->encode('ABC')->toGrid());
    }

    public function testLargestSymbolCapacity(): void
    {
        // R17x139 at level M holds 361 digits.
        self::assertSame(139, strlen((new RectangularMicroQrCode())->encode(str_repeat('5', 361))->toGrid()[0]));

        $this->expectException(InvalidDataException::class);
        (new RectangularMicroQrCode())->encode(str_repeat('5', 362));
    }

    public function testOnlyLevelsMAndH(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RectangularMicroQrCode(ErrorCorrectionLevel::L);
    }

    public function testUnknownSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RectangularMicroQrCode(size: 'R8x40');
    }
}
