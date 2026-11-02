<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbology\MicroQrCode;
use PhpBarcode\Symbology\QrCode\ErrorCorrectionLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MicroQrCodeTest extends TestCase
{
    /**
     * "01234567" in M2-L with mask 01; identical to segno's output and decoded by zxing-cpp.
     */
    public function testMatchesReferenceSymbol(): void
    {
        $expected = [
            '1111111010101',
            '1000001011101',
            '1011101001101',
            '1011101001111',
            '1011101011100',
            '1000001010001',
            '1111111001111',
            '0000000001100',
            '1101000010001',
            '0110101010101',
            '1110011111110',
            '0001010000110',
            '1110100110111',
        ];

        self::assertSame($expected, (new MicroQrCode(ErrorCorrectionLevel::L, mask: 1))->encode('01234567')->toGrid());
    }

    /**
     * @return iterable<string, array{string, ErrorCorrectionLevel, string, int}>
     */
    public static function versions(): iterable
    {
        yield 'M1 numeric' => ['12345', ErrorCorrectionLevel::L, 'Micro QR Code M1', 11];
        yield 'M2 alphanumeric' => ['HELLO', ErrorCorrectionLevel::L, 'Micro QR Code M2', 13];
        yield 'M3 byte' => ['hello', ErrorCorrectionLevel::L, 'Micro QR Code M3', 15];
        yield 'M4 at level Q' => ['12345', ErrorCorrectionLevel::Q, 'Micro QR Code M4', 17];
        yield 'M4-L numeric capacity' => [str_repeat('9', 35), ErrorCorrectionLevel::L, 'Micro QR Code M4', 17];
    }

    #[DataProvider('versions')]
    public function testPicksSmallestVersion(string $data, ErrorCorrectionLevel $level, string $name, int $size): void
    {
        $symbol = (new MicroQrCode($level))->encode($data);

        self::assertSame($name, $symbol->symbology());
        self::assertCount($size, $symbol->toGrid());
        self::assertSame(2, $symbol->quietZone());
    }

    public function testRejectsDataBeyondM4(): void
    {
        $this->expectException(InvalidDataException::class);

        (new MicroQrCode())->encode(str_repeat('9', 36));
    }

    public function testLevelHIsNotAvailable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MicroQrCode(ErrorCorrectionLevel::H);
    }

    public function testLevelQOnlyExistsInM4(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MicroQrCode(ErrorCorrectionLevel::Q, version: 3);
    }
}
