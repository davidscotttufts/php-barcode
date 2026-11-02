<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\MatrixSymbol;
use PhpBarcode\Symbology\Aztec\HighLevelEncoder;
use PhpBarcode\Symbology\AztecCode;
use PhpBarcode\Symbology\AztecRune;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AztecCodeTest extends TestCase
{
    /**
     * @return iterable<string, array{AztecCode, string, int}>
     */
    public static function sizes(): iterable
    {
        yield 'compact 1 layer' => [new AztecCode(), 'HELLO', 15];
        yield 'compact 2 layers' => [new AztecCode(layers: 2), 'A', 19];
        yield 'compact 4 layers' => [new AztecCode(layers: 4, compact: true), 'A', 27];
        yield 'full 1 layer' => [new AztecCode(layers: 1, compact: false), 'A', 19];
        yield 'full 4 layers' => [new AztecCode(layers: 4, compact: false), 'A', 31];
        yield 'full 32 layers' => [new AztecCode(layers: 32), 'A', 151];
    }

    #[DataProvider('sizes')]
    public function testSymbolSizes(AztecCode $aztec, string $data, int $size): void
    {
        $symbol = $aztec->encode($data);

        self::assertInstanceOf(MatrixSymbol::class, $symbol);
        self::assertSame($size, $symbol->width());
        self::assertSame($size, $symbol->height());
    }

    public function testCompactBullseyeIsDrawn(): void
    {
        $symbol = (new AztecCode())->encode('HELLO');
        self::assertInstanceOf(MatrixSymbol::class, $symbol);
        $center = 7;

        // Dark center, light ring at radius 1, dark ring at radius 2, light at 3, dark at 4.
        self::assertTrue($symbol->isDark($center, $center));
        self::assertFalse($symbol->isDark($center - 1, $center));
        self::assertTrue($symbol->isDark($center - 2, $center));
        self::assertFalse($symbol->isDark($center - 3, $center));
        self::assertTrue($symbol->isDark($center - 4, $center));
        self::assertSame([['row' => 3, 'column' => 3, 'size' => 9]], $symbol->finderPatterns);
    }

    public function testFullRangeBullseyeIsReportedAsFinder(): void
    {
        $symbol = (new AztecCode(compact: false))->encode('HELLO');
        self::assertInstanceOf(MatrixSymbol::class, $symbol);

        self::assertSame([['row' => 3, 'column' => 3, 'size' => 13]], $symbol->finderPatterns);
    }

    public function testGrowsWithData(): void
    {
        $small = (new AztecCode())->encode(str_repeat('A', 10));
        $large = (new AztecCode())->encode(str_repeat('A', 1000));
        self::assertInstanceOf(MatrixSymbol::class, $small);
        self::assertInstanceOf(MatrixSymbol::class, $large);

        self::assertGreaterThan($small->width(), $large->width());
    }

    public function testHigherErrorCorrectionNeedsALargerSymbol(): void
    {
        $data = 'Error correction comparison 1234567890';
        $low = (new AztecCode(errorCorrection: 10))->encode($data);
        $high = (new AztecCode(errorCorrection: 90))->encode($data);
        self::assertInstanceOf(MatrixSymbol::class, $low);
        self::assertInstanceOf(MatrixSymbol::class, $high);

        self::assertGreaterThan($low->width(), $high->width());
    }

    public function testRejectsDataThatDoesNotFit(): void
    {
        $this->expectException(InvalidDataException::class);
        (new AztecCode())->encode(str_repeat("\xFF", 4000));
    }

    public function testRejectsDataThatDoesNotFitForcedLayers(): void
    {
        $this->expectException(InvalidDataException::class);
        (new AztecCode(layers: 1))->encode(str_repeat('A', 100));
    }

    public function testRejectsInvalidOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AztecCode(layers: 5, compact: true);
    }

    public function testUpperCaseTextUsesFiveBitsPerCharacter(): void
    {
        $bits = (new HighLevelEncoder())->encode(HighLevelEncoder::bytes('ABC'));

        // A=2, B=3, C=4 in Upper mode, which is the initial mode.
        self::assertSame('000100001100100', $bits->toString());
    }

    public function testPunctuationPairsAreEncodedAsOneCode(): void
    {
        $bits = (new HighLevelEncoder())->encode(HighLevelEncoder::bytes("A\r\n"));

        // A, then P/S (0) + CR LF pair (2).
        self::assertSame('00010' . '00000' . '00010', $bits->toString());
    }

    public function testDigitsUseDigitMode(): void
    {
        $bits = (new HighLevelEncoder())->encode(HighLevelEncoder::bytes('123'));

        // D/L (30), then 4-bit digits 1=3, 2=4, 3=5.
        self::assertSame('11110' . '0011' . '0100' . '0101', $bits->toString());
    }

    public function testEciIsDeclaredForUtf8(): void
    {
        $bits = (new HighLevelEncoder())->encode([0x41], 26);

        // P/S, FLG(n), n=2, digits "2" and "6", then "A".
        self::assertSame('00000' . '00000' . '010' . '0100' . '1000' . '00010', $bits->toString());
    }

    public function testGs1StartsWithFnc1(): void
    {
        $bits = (new HighLevelEncoder())->encode([HighLevelEncoder::FNC1, 0x41]);

        // P/S, FLG(0), then "A".
        self::assertSame('00000' . '00000' . '000' . '00010', $bits->toString());
    }

    public function testLongBinaryRunsUseTheExtendedLengthHeader(): void
    {
        $bytes = array_fill(0, 100, 0xFF);
        $bits = (new HighLevelEncoder())->encode($bytes)->toString();

        // B/S, 5 zero bits, then 11-bit length (100 - 31).
        self::assertSame('11111' . '00000' . sprintf('%011b', 69), substr($bits, 0, 21));
        self::assertSame(21 + 800, strlen($bits));
    }

    public function testRuneSize(): void
    {
        $symbol = (new AztecRune())->encode('25');
        self::assertInstanceOf(MatrixSymbol::class, $symbol);

        self::assertSame(11, $symbol->width());
        self::assertSame('25', $symbol->text());
    }

    public function testRunesDiffer(): void
    {
        self::assertNotSame((new AztecRune())->encode('0')->toGrid(), (new AztecRune())->encode('1')->toGrid());
    }

    public function testRuneRejectsOutOfRangeValues(): void
    {
        self::assertFalse((new AztecRune())->supports('256'));
        self::assertFalse((new AztecRune())->supports('-1'));
        self::assertTrue((new AztecRune())->supports('255'));
    }
}
