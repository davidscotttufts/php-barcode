<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\MatrixSymbol;
use PhpBarcode\Symbology\Pdf417;
use PhpBarcode\Symbology\Pdf417\ErrorCorrection;
use PhpBarcode\Symbology\Pdf417\HighLevelEncoder;
use PhpBarcode\Symbology\Pdf417\MacroSegment;
use PhpBarcode\Symbology\Pdf417\Tables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Pdf417Test extends TestCase
{
    private const START = '11111111010101000';
    private const STOP = '111111101000101001';

    public function testTextCompactionGolden(): void
    {
        // P D F (alpha) → ML → 4 1 7 (mixed) → PS pad: pairs 15·30+3, 5·30+28, 4·30+1, 7·30+29.
        self::assertSame([453, 178, 121, 239], HighLevelEncoder::encode('PDF417'));
    }

    public function testNumericCompactionMatchesIsoExample(): void
    {
        // ISO/IEC 15438 numeric compaction example.
        self::assertSame([1, 624, 434, 632, 282, 200], HighLevelEncoder::numeric('000213298174000'));
    }

    public function testLongDigitRunsUseNumericCompaction(): void
    {
        $codewords = HighLevelEncoder::encode(str_repeat('9', 50));

        self::assertSame(HighLevelEncoder::LATCH_NUMERIC, $codewords[0]);
        // 44 digits → 15 codewords, 6 digits → 3 codewords.
        self::assertCount(1 + 15 + 3, $codewords);
    }

    public function testByteCompactionPacksSixBytesIntoFiveCodewords(): void
    {
        $codewords = HighLevelEncoder::bytes("\x80\x81\x82\x83\x84\x85");

        self::assertSame(HighLevelEncoder::LATCH_BYTE_SIX, $codewords[0]);
        self::assertCount(6, $codewords);

        $value = 0;
        foreach (array_slice($codewords, 1) as $codeword) {
            $value = $value * 900 + $codeword;
        }
        self::assertSame(0x808182838485, $value);
    }

    public function testSingleByteInTextUsesShift(): void
    {
        $codewords = HighLevelEncoder::encode("ABCDE\x01FGHIJ");

        self::assertContains(HighLevelEncoder::SHIFT_BYTE, $codewords);
        self::assertNotContains(HighLevelEncoder::LATCH_BYTE, $codewords);
    }

    public function testErrorCorrectionGeneratorsMatchIsoTables(): void
    {
        self::assertSame([27, 917], ErrorCorrection::generator(2));
        self::assertSame([522, 568, 723, 809], ErrorCorrection::generator(4));
        self::assertSame([237, 308, 436, 284, 646, 653, 428, 379], ErrorCorrection::generator(8));
        self::assertSame(512, ErrorCorrection::codewordsForLevel(8));
    }

    public function testUtf8AddsEciAutomatically(): void
    {
        $symbol = (new Pdf417())->encode('Grüße');
        $plain = (new Pdf417(eci: false))->encode('Grüße');

        self::assertInstanceOf(MatrixSymbol::class, $symbol);
        self::assertSame('Grüße', $symbol->text());
        self::assertNotSame($symbol->toGrid(), $plain->toGrid());
        self::assertSame([927, 26], HighLevelEncoder::eci(26));
        self::assertSame([926, 0, 0], HighLevelEncoder::eci(900));
    }

    /**
     * @return iterable<string, array{Pdf417, string}>
     */
    public static function symbols(): iterable
    {
        yield 'text' => [new Pdf417(), 'Hello, World!'];
        yield 'digits' => [new Pdf417(), str_repeat('0123456789', 8)];
        yield 'bytes' => [new Pdf417(), "\x00\x01\x02\xFF\xFE"];
        yield 'fixed columns' => [new Pdf417(columns: 7), 'Seven columns'];
        yield 'high ecc' => [new Pdf417(errorCorrectionLevel: 6), 'Level six'];
    }

    #[DataProvider('symbols')]
    public function testRowStructure(Pdf417 $pdf417, string $data): void
    {
        $symbol = $pdf417->encode($data);
        self::assertInstanceOf(MatrixSymbol::class, $symbol);
        $grid = $symbol->toGrid();

        self::assertGreaterThanOrEqual(3, count($grid));
        self::assertLessThanOrEqual(90, count($grid));

        $width = strlen($grid[0]);
        $columns = ($width - 69) / 17;
        self::assertSame(0, ($width - 69) % 17, 'Row width is 17 × columns + 69 modules');
        self::assertGreaterThanOrEqual(1, $columns);

        foreach ($grid as $row) {
            self::assertStringStartsWith(self::START, $row);
            self::assertStringEndsWith(self::STOP, $row);
        }

        // Row 0's left row indicator encodes (rows − 1) div 3 in cluster 0.
        self::assertSame(Tables::codeword(intdiv(count($grid) - 1, 3), 0), substr($grid[0], 17, 17));
    }

    public function testColumnsAreHonoured(): void
    {
        $grid = (new Pdf417(columns: 7))->encode('Seven columns')->toGrid();

        self::assertSame(17 * 7 + 69, strlen($grid[0]));
    }

    public function testCompactDropsRightIndicatorAndStop(): void
    {
        $normal = (new Pdf417(columns: 4))->encode('Compact')->toGrid();
        $compact = (new Pdf417(columns: 4, compact: true))->encode('Compact')->toGrid();

        self::assertSame(strlen($normal[0]) - 34, strlen($compact[0]));
        self::assertStringEndsWith('1', $compact[0]);
        self::assertSame('Compact PDF417', (new Pdf417(compact: true))->name());
    }

    public function testLargePayloadFits(): void
    {
        $grid = (new Pdf417())->encode(str_repeat("\xA5", 1000))->toGrid();

        self::assertNotEmpty($grid);
    }

    public function testTooMuchDataIsRejected(): void
    {
        $this->expectException(InvalidDataException::class);

        (new Pdf417())->encode(random_bytes(1200));
    }

    public function testDataThatDoesNotFitFixedSizeIsRejected(): void
    {
        $this->expectException(InvalidDataException::class);

        (new Pdf417(columns: 1, rows: 3))->encode('This is far too long for three codewords');
    }

    public function testInvalidOptionsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Pdf417(columns: 31);
    }

    public function testMacroSegmentControlBlock(): void
    {
        self::assertSame(
            [928, 111, 100, 123, 456, 923, 1, 111, 103],
            (new MacroSegment(1, 3, '123456'))->codewords(),
        );
        self::assertSame(922, (new MacroSegment(3, 3, '123456'))->codewords()[9]);

        $this->expectException(\InvalidArgumentException::class);
        new MacroSegment(4, 3);
    }

    public function testRowHeightAndQuietZoneFlowIntoLayout(): void
    {
        $symbol = (new Pdf417(rowHeight: 4))->encode('Rows');
        $grid = $symbol->toGrid();
        $layout = $symbol->layout($symbol->defaultRenderOptions()->withModuleWidth(1)->withQuietZone(0));

        self::assertEqualsWithDelta(count($grid) * 4, $layout->height, 0.001);
        self::assertSame(2, $symbol->quietZone());
    }
}
