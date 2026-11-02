<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\MatrixSymbol;
use PhpBarcode\Symbology\Pdf417\ErrorCorrection;
use PhpBarcode\Symbology\Pdf417\MacroSegment;
use PhpBarcode\Symbology\Pdf417\Tables;

/**
 * MicroPDF417 (ISO/IEC 24728): a compact relative of PDF417 for small items,
 * with 1 to 4 data columns and up to 150 bytes, 250 text characters or 366 digits.
 * Rows are identified by row address patterns instead of start/stop patterns.
 *
 *     new MicroPdf417();            // smallest symbol that fits
 *     new MicroPdf417(columns: 2);  // force a column count
 */
final class MicroPdf417 extends AbstractPdf417
{
    private const STOP = '1';

    /**
     * @param int|null $columns   Data columns (1-4); null picks the smallest symbol that fits.
     * @param int      $rowHeight Row height in modules (ISO/IEC 24728 recommends at least 2).
     */
    public function __construct(
        private readonly ?int $columns = null,
        private readonly int $rowHeight = 3,
        int|false|null $eci = null,
        ?MacroSegment $macro = null,
    ) {
        parent::__construct($eci, $macro);

        if ($columns !== null && ($columns < 1 || $columns > 4)) {
            throw new \InvalidArgumentException('MicroPDF417 symbols have 1 to 4 data columns.');
        }
        if ($rowHeight < 1) {
            throw new \InvalidArgumentException('The row height must be at least one module.');
        }
    }

    public function name(): string
    {
        return 'MicroPDF417';
    }

    public function quietZone(): int
    {
        return 2;
    }

    protected function doEncode(string $data): MatrixSymbol
    {
        $content = $this->dataCodewords($data, startInByteMode: true);
        $macro = $this->macroCodewords();
        $needed = count($content) + count($macro);

        [$columns, $rows, $ecCount, $leftRap, $centreRap, $rightRap, $cluster] = $this->variantFor($needed);

        $padding = $columns * $rows - $ecCount - $needed;
        $codewords = [...$content, ...array_fill(0, $padding, 900), ...$macro];
        $codewords = [...$codewords, ...ErrorCorrection::encode($codewords, $ecCount)];

        $lines = [];
        for ($row = 0; $row < $rows; $row++) {
            $left = self::rap(Tables::RAP_SIDE, $leftRap + $row);
            $right = self::rap(Tables::RAP_SIDE, $rightRap + $row);
            $centre = $centreRap > 0 ? self::rap(Tables::RAP_CENTRE, $centreRap + $row) : '';
            $clusterRow = $cluster + $row; // Tables::codeword() takes the cluster from row mod 3.

            $symbols = array_map(
                static fn(int $codeword): string => Tables::codeword($codeword, $clusterRow),
                array_slice($codewords, $row * $columns, $columns),
            );

            // Three-column symbols put the centre RAP after the first column, four-column ones after the second.
            $line = $left . match ($columns) {
                3 => $symbols[0] . $centre . $symbols[1] . $symbols[2],
                4 => $symbols[0] . $symbols[1] . $centre . $symbols[2] . $symbols[3],
                default => implode('', $symbols),
            };
            $lines[] = $line . $right . self::STOP;
        }

        return new MatrixSymbol($lines, $this->humanReadable($data), $this->name(), $this->quietZone(), $this->rowHeight);
    }

    /**
     * The variant with the least spare capacity for $needed data codewords (ties go to the smaller area).
     *
     * @return array{int, int, int, int, int, int, int}
     */
    private function variantFor(int $needed): array
    {
        $best = null;
        $bestScore = [PHP_INT_MAX, PHP_INT_MAX];

        foreach (Tables::MICRO_VARIANTS as $variant) {
            [$columns, $rows, $ecCount] = $variant;
            if ($this->columns !== null && $columns !== $this->columns) {
                continue;
            }
            $capacity = $columns * $rows - $ecCount;
            if ($capacity < $needed) {
                continue;
            }
            $score = [$capacity, self::moduleWidth($columns) * $rows];
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $variant;
            }
        }

        if ($best === null) {
            throw new InvalidDataException(sprintf(
                'The data needs %d codewords, more than %s can hold.',
                $needed,
                $this->columns === null ? 'MicroPDF417' : sprintf('a %d-column MicroPDF417 symbol', $this->columns),
            ));
        }

        return $best;
    }

    private static function moduleWidth(int $columns): int
    {
        // Left RAP (10) + data + optional centre RAP (10) + right RAP (10) + stop (1).
        return 21 + 17 * $columns + ($columns >= 3 ? 10 : 0);
    }

    /**
     * Row address pattern for a 1-based RAP number, wrapping after 52.
     *
     * @param list<int> $table
     */
    private static function rap(array $table, int $number): string
    {
        return sprintf('%010b', $table[($number - 1) % 52]);
    }
}
