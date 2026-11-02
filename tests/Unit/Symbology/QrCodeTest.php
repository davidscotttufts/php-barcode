<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\MatrixSymbol;
use PhpBarcode\Symbology\QrCode;
use PhpBarcode\Symbology\QrCode\ErrorCorrectionLevel;
use PhpBarcode\Symbology\QrCode\Mode;
use PhpBarcode\Symbology\QrCode\QrSpec;
use PhpBarcode\Symbology\QrCode\Segmenter;
use PhpBarcode\Symbology\QrCode\StructuredAppend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QrCodeTest extends TestCase
{
    /**
     * ISO/IEC 18004 Annex I example ("01234567", version 1-M, mask 010);
     * identical to segno's output and decoded by zxing-cpp.
     */
    public function testMatchesReferenceSymbol(): void
    {
        $expected = [
            '111111100101101111111',
            '100000100111101000001',
            '101110101000001011101',
            '101110101100001011101',
            '101110101011101011101',
            '100000101000101000001',
            '111111101010101111111',
            '000000001001100000000',
            '101111100100101111100',
            '000101011010100101100',
            '001000110101010011111',
            '000010000100000111100',
            '000111111001010010000',
            '000000001011111001100',
            '111111100110101100000',
            '100000101011111000101',
            '101110101000100101100',
            '101110101100100100000',
            '101110101011010010100',
            '100000100000000110110',
            '111111101111010010100',
        ];

        self::assertSame($expected, (new QrCode(ErrorCorrectionLevel::M, mask: 2))->encode('01234567')->toGrid());
    }

    public function testSymbolMetadata(): void
    {
        $symbol = (new QrCode())->encode('Hello, World!');

        self::assertInstanceOf(MatrixSymbol::class, $symbol);
        self::assertSame('QR Code', $symbol->symbology());
        self::assertSame(4, $symbol->quietZone());
        self::assertSame(21, $symbol->width());
        self::assertSame([
            ['row' => 0, 'column' => 0, 'size' => 7],
            ['row' => 0, 'column' => 14, 'size' => 7],
            ['row' => 14, 'column' => 0, 'size' => 7],
        ], $symbol->finderPatterns);
    }

    /**
     * @return iterable<string, array{string, ErrorCorrectionLevel, int}>
     */
    public static function capacityBoundaries(): iterable
    {
        // Published capacities (ISO/IEC 18004 Table 7).
        yield '1-L numeric fits' => [str_repeat('1', 41), ErrorCorrectionLevel::L, 1];
        yield '1-L numeric overflows' => [str_repeat('1', 42), ErrorCorrectionLevel::L, 2];
        yield '1-H alphanumeric fits' => [str_repeat('A', 10), ErrorCorrectionLevel::H, 1];
        yield '1-H alphanumeric overflows' => [str_repeat('A', 11), ErrorCorrectionLevel::H, 2];
        yield '10-M bytes fit' => [str_repeat('a', 213), ErrorCorrectionLevel::M, 10];
        yield '40-L bytes fit' => [str_repeat('a', 2953), ErrorCorrectionLevel::L, 40];
        yield '40-H numeric fits' => [str_repeat('9', 3057), ErrorCorrectionLevel::H, 40];
    }

    #[DataProvider('capacityBoundaries')]
    public function testPicksSmallestVersion(string $data, ErrorCorrectionLevel $level, int $version): void
    {
        $symbol = (new QrCode($level))->encode($data);

        self::assertInstanceOf(MatrixSymbol::class, $symbol);
        self::assertSame(QrSpec::size($version), $symbol->width());
    }

    public function testRejectsDataBeyondVersion40(): void
    {
        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage('too long for a QR Code');

        (new QrCode(ErrorCorrectionLevel::L))->encode(str_repeat('a', 2954));
    }

    public function testForcedVersionThatIsTooSmallFails(): void
    {
        $this->expectException(InvalidDataException::class);

        (new QrCode(version: 1))->encode(str_repeat('x', 100));
    }

    public function testForcedVersionAndMinVersion(): void
    {
        self::assertSame(45, count((new QrCode(version: 7))->encode('A')->toGrid()));
        self::assertSame(QrSpec::size(5), strlen((new QrCode(minVersion: 5))->encode('A')->toGrid()[0]));
    }

    public function testBoostingRaisesTheLevelWithoutGrowing(): void
    {
        $plain = (new QrCode(ErrorCorrectionLevel::L))->encode('A');
        $boosted = (new QrCode(ErrorCorrectionLevel::L, boostErrorCorrection: true))->encode('A');

        self::assertCount(21, $boosted->toGrid());
        self::assertNotSame($plain->toGrid(), $boosted->toGrid());
    }

    public function testHigherLevelsNeedLargerSymbols(): void
    {
        $data = str_repeat('QR CODE ', 10);
        $sizes = array_map(
            static fn(ErrorCorrectionLevel $level): int => strlen((new QrCode($level))->encode($data)->toGrid()[0]),
            ErrorCorrectionLevel::cases(),
        );

        self::assertGreaterThan($sizes[0], $sizes[3]);
        $sorted = $sizes;
        sort($sorted);
        self::assertSame($sorted, $sizes);
    }

    public function testEveryMaskProducesADistinctSymbol(): void
    {
        $grids = array_map(static fn(int $mask): array => (new QrCode(mask: $mask))->encode('mask')->toGrid(), range(0, 7));

        self::assertCount(8, array_unique(array_map('serialize', $grids)));
    }

    public function testGs1RejectsNonAsciiAndAcceptsGroupSeparators(): void
    {
        self::assertTrue((new QrCode(gs1: true))->supports("0109506000134352\x1D10ABC"));
        self::assertFalse((new QrCode(gs1: true))->supports('01é'));
    }

    public function testStructuredAppendValidation(): void
    {
        self::assertSame(ord('A') ^ ord('B'), StructuredAppend::parityOf('AB'));

        $this->expectException(\InvalidArgumentException::class);
        new StructuredAppend(2, 2, 0);
    }

    public function testInvalidConstructorArguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new QrCode(version: 41);
    }

    public function testSegmenterChoosesCheapestModes(): void
    {
        $segments = Segmenter::optimize(
            Segmenter::units('abc0123456789012345XYZ', false, false),
            [Mode::Numeric, Mode::Alphanumeric, Mode::Byte, Mode::Kanji],
            static fn(Mode $mode): int => 4 + $mode->characterCountBits(1),
        );

        self::assertNotNull($segments);
        self::assertSame(
            [[Mode::Byte, 'abc'], [Mode::Numeric, '0123456789012345'], [Mode::Alphanumeric, 'XYZ']],
            array_map(static fn($s): array => [$s->mode, $s->payload], $segments),
        );
    }

    public function testSegmenterUsesKanjiForJapanese(): void
    {
        if (!function_exists('mb_convert_encoding')) {
            self::markTestSkipped('mbstring is required for Kanji mode.');
        }

        $segments = Segmenter::optimize(
            Segmenter::units('漢字', true, false, nonAsciiBytes: false),
            [Mode::Numeric, Mode::Alphanumeric, Mode::Byte, Mode::Kanji],
            static fn(Mode $mode): int => 4 + $mode->characterCountBits(1),
        );

        self::assertNotNull($segments);
        self::assertCount(1, $segments);
        self::assertSame(Mode::Kanji, $segments[0]->mode);
        self::assertSame("\x8a\xbf\x8e\x9a", $segments[0]->payload);
    }

    public function testGs1EscapesPercentInAlphanumericMode(): void
    {
        $units = Segmenter::units("A\x1D%", false, true);

        self::assertSame(['A', '%', '%%'], array_column($units, 'alnum'));
    }

    public function testVersion40EncodesQuickly(): void
    {
        $start = hrtime(true);
        (new QrCode(ErrorCorrectionLevel::L))->encode(str_repeat('z', 2953));

        self::assertLessThan(2.0, (hrtime(true) - $start) / 1e9);
    }
}
