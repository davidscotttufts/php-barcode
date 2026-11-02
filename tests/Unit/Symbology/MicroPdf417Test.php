<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbology\MicroPdf417;
use PhpBarcode\Symbology\Pdf417\ErrorCorrection;
use PhpBarcode\Symbology\Pdf417\Tables;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MicroPdf417Test extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function columnWidths(): iterable
    {
        // Left RAP 10 + 17 per column + centre RAP 10 (3-4 columns) + right RAP 10 + stop 1.
        yield '1 column' => [1, 38];
        yield '2 columns' => [2, 55];
        yield '3 columns' => [3, 82];
        yield '4 columns' => [4, 99];
    }

    #[DataProvider('columnWidths')]
    public function testRowWidthPerColumnCount(int $columns, int $width): void
    {
        $grid = (new MicroPdf417(columns: $columns))->encode('MICRO')->toGrid();

        self::assertSame($width, strlen($grid[0]));
        foreach ($grid as $row) {
            self::assertStringEndsWith('1', $row);
        }
    }

    public function testRowsMatchAVariant(): void
    {
        $grid = (new MicroPdf417(columns: 2))->encode('Two columns here')->toGrid();
        $rowCounts = array_map(
            static fn(array $variant): int => $variant[1],
            array_filter(Tables::MICRO_VARIANTS, static fn(array $variant): bool => $variant[0] === 2),
        );

        self::assertContains(count($grid), $rowCounts);
    }

    public function testFirstRowStartsWithTheVariantsLeftRap(): void
    {
        // The smallest variant (1 column × 11 rows) starts at left RAP 1.
        $grid = (new MicroPdf417())->encode('A')->toGrid();

        self::assertCount(11, $grid);
        self::assertSame(sprintf('%010b', Tables::RAP_SIDE[0]), substr($grid[0], 0, 10));
    }

    public function testMicroCoefficientsUseTheSharedGenerator(): void
    {
        // ISO/IEC 24728 Annex F, k = 7.
        self::assertSame([76, 925, 537, 597, 784, 691, 437], ErrorCorrection::generator(7));
    }

    public function testCapacityLimits(): void
    {
        self::assertNotEmpty((new MicroPdf417())->encode(str_repeat('9', 300))->toGrid());

        $this->expectException(InvalidDataException::class);
        (new MicroPdf417(columns: 1))->encode(str_repeat('ABCDEFGHIJ', 10));
    }

    public function testInvalidColumnsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MicroPdf417(columns: 5);
    }
}
