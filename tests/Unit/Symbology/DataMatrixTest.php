<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\MatrixSymbol;
use PhpBarcode\Symbology\DataMatrix;
use PhpBarcode\Symbology\DataMatrix\DataMatrixShape;
use PhpBarcode\Symbology\DataMatrix\StructuredAppend;
use PhpBarcode\Symbology\DataMatrix\SymbolSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataMatrixTest extends TestCase
{
    public function testIsoReferenceCodewords(): void
    {
        // ISO/IEC 16022 Annex O: "123456" → 10×10, data 142 164 186, check 114 25 5 88 102.
        $result = (new DataMatrix())->codewords('123456');

        self::assertSame('10x10', $result['size']->name());
        self::assertSame([142, 164, 186], $result['data']);
        self::assertSame([142, 164, 186, 114, 25, 5, 88, 102], $result['codewords']);
    }

    public function testGolden10x10Grid(): void
    {
        self::assertSame([
            '1010101010',
            '1100101101',
            '1100000100',
            '1100011101',
            '1100001000',
            '1000001111',
            '1110110000',
            '1111011001',
            '1001110100',
            '1111111111',
        ], (new DataMatrix())->encode('123456')->toGrid());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function latchProvider(): iterable
    {
        yield 'C40 for upper case' => ['ABCDEFGHIJKLMNOP', 230];
        yield 'Text for lower case' => ['abcdefghijklmnop', 239];
        yield 'X12 for EDI segments' => ["ABC*DEF>GHI*JKL\r", 238];
        yield 'EDIFACT for punctuation' => ['.A.C1.3.X.X2..AB.CD', 240];
        yield 'Base 256 for binary' => ["\x80\x81\x82\x83\x84\x85\x86\x87", 231];
    }

    #[DataProvider('latchProvider')]
    public function testChoosesTheCompactEncodation(string $data, int $latch): void
    {
        $codewords = (new DataMatrix(eci: DataMatrix::NO_ECI))->codewords($data)['data'];

        self::assertContains($latch, array_slice($codewords, 0, 2));
    }

    public function testDigitsArePackedInPairs(): void
    {
        self::assertSame([142, 164, 186, 129, 175], (new DataMatrix(shape: DataMatrixShape::Any))->codewords('123456')['data'] + [3 => 129, 4 => 175]);
        self::assertCount(5, (new DataMatrix())->codewords('1234567890')['data']); // 12×12
    }

    public function testGs1StartsWithFnc1AndEncodesSeparatorsAsFnc1(): void
    {
        $dm = new DataMatrix(gs1: true);
        $data = $dm->codewords("10ABC\x1D21XYZ")['data'];

        self::assertSame(232, $data[0]);
        self::assertSame('GS1 DataMatrix', $dm->name());
        self::assertNotContains(30, $data, 'GS must not be encoded as a plain ASCII character');
    }

    public function testGs1RejectsNonAsciiAndLeadingSeparator(): void
    {
        self::assertFalse((new DataMatrix(gs1: true))->supports("\x1D10ABC"));
        self::assertFalse((new DataMatrix(gs1: true))->supports("10ABC\xE9"));
    }

    public function testUtf8GetsEci26Automatically(): void
    {
        $data = (new DataMatrix())->codewords('Größe')['data'];
        self::assertSame([241, 27], array_slice($data, 0, 2));

        self::assertNotSame(241, (new DataMatrix(eci: DataMatrix::NO_ECI))->codewords('Größe')['data'][0]);
        self::assertNotSame(241, (new DataMatrix())->codewords('plain ascii')['data'][0]);
    }

    public function testExplicitEciUsesMultiByteDesignators(): void
    {
        self::assertSame([241, 4], array_slice((new DataMatrix(eci: 3))->codewords('A')['data'], 0, 2));
        self::assertSame([241, 128, 1], array_slice((new DataMatrix(eci: 127))->codewords('A')['data'], 0, 3));
    }

    public function testMacro05HeaderIsCompressed(): void
    {
        $data = (new DataMatrix())->codewords("[)>\x1E05\x1DABC\x1E\x04")['data'];

        self::assertSame(236, $data[0]);
    }

    public function testStructuredAppend(): void
    {
        $data = (new DataMatrix(structuredAppend: new StructuredAppend(2, 3, 300)))->codewords('PART')['data'];

        self::assertSame([233, (1 << 4) | 14, 2, 47], array_slice($data, 0, 4));
    }

    public function testLongBase256UsesTwoByteLengthField(): void
    {
        $bytes = str_repeat("\x80\xFF\x90", 100);
        $symbol = (new DataMatrix(eci: DataMatrix::NO_ECI))->encode($bytes);

        self::assertInstanceOf(MatrixSymbol::class, $symbol);
        self::assertGreaterThanOrEqual(300, (new DataMatrix(eci: DataMatrix::NO_ECI))->codewords($bytes)['size']->dataCodewords);
    }

    public function testShapeSelection(): void
    {
        self::assertSame('8x18', (new DataMatrix(shape: DataMatrixShape::Rectangle))->codewords('ABC')['size']->name());
        self::assertSame('12x12', (new DataMatrix(shape: DataMatrixShape::Any))->codewords('ABCD')['size']->name());
        self::assertSame('8x48', (new DataMatrix(shape: DataMatrixShape::Rectangle, dmre: true))->codewords(str_repeat('A', 24))['size']->name());
        self::assertSame('12x36', (new DataMatrix(shape: DataMatrixShape::Rectangle))->codewords(str_repeat('A', 24))['size']->name());
        self::assertSame('32x32', (new DataMatrix(size: '32x32'))->codewords('A')['size']->name());
    }

    public function testDmreSizesRequireOptIn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DataMatrix(size: '8x48');
    }

    public function testRejectsUnknownSizes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DataMatrix(size: '11x11');
    }

    public function testTooMuchDataThrows(): void
    {
        $this->expectException(InvalidDataException::class);
        (new DataMatrix(size: '10x10'))->encode('ABCDEFGHIJ');
    }

    public function testMaximumSymbolIsFastAndStructurallySound(): void
    {
        $start = microtime(true);
        $symbol = (new DataMatrix())->encode(str_repeat('0123456789', 311));
        self::assertLessThan(0.5, microtime(true) - $start);

        $grid = $symbol->toGrid();
        self::assertCount(144, $grid);
        self::assertSame(144, strlen($grid[0]));

        // 6×6 data regions of 22×22: every region has a solid left edge and an alternating top edge.
        foreach ([0, 24, 48, 72, 96, 120] as $offset) {
            self::assertSame('1', $grid[$offset + 5][$offset]);
            self::assertSame(str_repeat('10', 12), substr($grid[$offset], $offset, 24));
            self::assertSame(str_repeat('1', 24), substr($grid[$offset + 23], $offset, 24));
        }
    }

    public function testEverySizeHasConsistentGeometry(): void
    {
        foreach (SymbolSize::all() as $size) {
            self::assertSame($size->rows, $size->verticalRegions * ($size->regionRows + 2), $size->name());
            self::assertSame($size->columns, $size->horizontalRegions * ($size->regionColumns + 2), $size->name());
            self::assertSame(0, $size->ecCodewords % $size->blocks, $size->name());

            // Mapping matrix holds exactly the codewords (8 bits each), allowing the 4-module fixed corner.
            $modules = $size->mappingRows() * $size->mappingColumns();
            $codewords = $size->dataCodewords + $size->ecCodewords;
            self::assertContains($modules - 8 * $codewords, [0, 4], $size->name());

            $symbol = (new DataMatrix(size: $size->name(), dmre: $size->dmre))->encode('A');
            self::assertSame($size->rows, count($symbol->toGrid()), $size->name());
        }
    }
}
