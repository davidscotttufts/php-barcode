<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\DataMatrix;

/**
 * Places codewords into the mapping matrix and wraps it with finder and
 * timing patterns (ISO/IEC 16022 Annex F, "ECC 200 symbol character placement").
 *
 * @internal
 */
final class Placement
{
    private const UNSET = -1;
    private const DARK = -2;

    /** @var array<int, int> Codeword index × 8 + bit (0 = most significant), or DARK/UNSET, by row × columns + column. */
    private array $cells;

    private function __construct(
        private readonly int $rows,
        private readonly int $columns,
    ) {
        $this->cells = array_fill(0, $rows * $columns, self::UNSET);
    }

    /**
     * @param list<int> $codewords Interleaved data and error-correction codewords.
     *
     * @return list<string> Rows of "0"/"1" for the complete symbol.
     */
    public static function symbol(SymbolSize $size, array $codewords): array
    {
        $placement = new self($size->mappingRows(), $size->mappingColumns());
        $placement->placeAll();

        $grid = array_fill(0, $size->rows, array_fill(0, $size->columns, '0'));
        $h = $size->regionRows;
        $w = $size->regionColumns;

        // Finder ("L" on the left and bottom) and timing (alternating top and right) patterns.
        for ($ry = 0; $ry < $size->verticalRegions; $ry++) {
            for ($rx = 0; $rx < $size->horizontalRegions; $rx++) {
                $top = $ry * ($h + 2);
                $left = $rx * ($w + 2);
                for ($x = 0; $x < $w + 2; $x++) {
                    $grid[$top][$left + $x] = $x % 2 === 0 ? '1' : '0';
                    $grid[$top + $h + 1][$left + $x] = '1';
                }
                for ($y = 0; $y < $h + 2; $y++) {
                    $grid[$top + $y][$left] = '1';
                    $grid[$top + $y][$left + $w + 1] = $y % 2 === 1 ? '1' : '0';
                }
            }
        }

        for ($r = 0; $r < $placement->rows; $r++) {
            $y = intdiv($r, $h) * ($h + 2) + 1 + $r % $h;
            for ($c = 0; $c < $placement->columns; $c++) {
                $cell = $placement->cells[$r * $placement->columns + $c];
                $dark = $cell === self::DARK
                    || ($cell >= 0 && (($codewords[$cell >> 3] ?? 0) >> (7 - ($cell & 7)) & 1) === 1);
                if ($dark) {
                    $grid[$y][intdiv($c, $w) * ($w + 2) + 1 + $c % $w] = '1';
                }
            }
        }

        return array_map(static fn(array $row): string => implode('', $row), $grid);
    }

    private function placeAll(): void
    {
        $nrow = $this->rows;
        $ncol = $this->columns;
        $chr = 0;
        $row = 4;
        $col = 0;

        do {
            $chr = $this->corners($row, $col, $chr);
            [$row, $col, $chr] = $this->sweepUpRight($row, $col, $chr);
            [$row, $col, $chr] = $this->sweepDownLeft($row + 1, $col + 3, $chr);
            $row += 3;
            $col++;
        } while ($row < $nrow || $col < $ncol);

        // Unused lower-right 2×2 corner: fixed checkerboard.
        if ($this->cells[$nrow * $ncol - 1] === self::UNSET) {
            $this->cells[$nrow * $ncol - 1] = self::DARK;
            $this->cells[$nrow * $ncol - $ncol - 2] = self::DARK;
        }
    }

    /**
     * The four special corner characters, placed when the sweep reaches them.
     *
     * @return int The next character index.
     */
    private function corners(int $row, int $col, int $chr): int
    {
        $nrow = $this->rows;
        $ncol = $this->columns;

        if ($row === $nrow && $col === 0) {
            $this->corner1($chr++);
        }
        if ($row === $nrow - 2 && $col === 0 && $ncol % 4 !== 0) {
            $this->corner2($chr++);
        }
        if ($row === $nrow - 2 && $col === 0 && $ncol % 8 === 4) {
            $this->corner3($chr++);
        }
        if ($row === $nrow + 4 && $col === 2 && $ncol % 8 === 0) {
            $this->corner4($chr++);
        }

        return $chr;
    }

    /**
     * @return array{int, int, int} Row, column and next character index after the sweep.
     */
    private function sweepUpRight(int $row, int $col, int $chr): array
    {
        do {
            if ($row < $this->rows && $col >= 0 && $this->cells[$row * $this->columns + $col] === self::UNSET) {
                $this->utah($row, $col, $chr++);
            }
            $row -= 2;
            $col += 2;
        } while ($row >= 0 && $col < $this->columns);

        return [$row, $col, $chr];
    }

    /**
     * @return array{int, int, int} Row, column and next character index after the sweep.
     */
    private function sweepDownLeft(int $row, int $col, int $chr): array
    {
        do {
            if ($row >= 0 && $col < $this->columns && $this->cells[$row * $this->columns + $col] === self::UNSET) {
                $this->utah($row, $col, $chr++);
            }
            $row += 2;
            $col -= 2;
        } while ($row < $this->rows && $col >= 0);

        return [$row, $col, $chr];
    }

    private function module(int $row, int $col, int $chr, int $bit): void
    {
        if ($row < 0) {
            $row += $this->rows;
            $col += 4 - (($this->rows + 4) % 8);
        }
        if ($col < 0) {
            $col += $this->columns;
            $row += 4 - (($this->columns + 4) % 8);
        }
        $this->cells[$row * $this->columns + $col] = ($chr << 3) | $bit;
    }

    /** The standard L-shaped 8-module symbol character. */
    private function utah(int $row, int $col, int $chr): void
    {
        $this->module($row - 2, $col - 2, $chr, 0);
        $this->module($row - 2, $col - 1, $chr, 1);
        $this->module($row - 1, $col - 2, $chr, 2);
        $this->module($row - 1, $col - 1, $chr, 3);
        $this->module($row - 1, $col, $chr, 4);
        $this->module($row, $col - 2, $chr, 5);
        $this->module($row, $col - 1, $chr, 6);
        $this->module($row, $col, $chr, 7);
    }

    private function corner1(int $chr): void
    {
        $r = $this->rows;
        $c = $this->columns;
        $this->module($r - 1, 0, $chr, 0);
        $this->module($r - 1, 1, $chr, 1);
        $this->module($r - 1, 2, $chr, 2);
        $this->module(0, $c - 2, $chr, 3);
        $this->module(0, $c - 1, $chr, 4);
        $this->module(1, $c - 1, $chr, 5);
        $this->module(2, $c - 1, $chr, 6);
        $this->module(3, $c - 1, $chr, 7);
    }

    private function corner2(int $chr): void
    {
        $r = $this->rows;
        $c = $this->columns;
        $this->module($r - 3, 0, $chr, 0);
        $this->module($r - 2, 0, $chr, 1);
        $this->module($r - 1, 0, $chr, 2);
        $this->module(0, $c - 4, $chr, 3);
        $this->module(0, $c - 3, $chr, 4);
        $this->module(0, $c - 2, $chr, 5);
        $this->module(0, $c - 1, $chr, 6);
        $this->module(1, $c - 1, $chr, 7);
    }

    private function corner3(int $chr): void
    {
        $r = $this->rows;
        $c = $this->columns;
        $this->module($r - 3, 0, $chr, 0);
        $this->module($r - 2, 0, $chr, 1);
        $this->module($r - 1, 0, $chr, 2);
        $this->module(0, $c - 2, $chr, 3);
        $this->module(0, $c - 1, $chr, 4);
        $this->module(1, $c - 1, $chr, 5);
        $this->module(2, $c - 1, $chr, 6);
        $this->module(3, $c - 1, $chr, 7);
    }

    private function corner4(int $chr): void
    {
        $r = $this->rows;
        $c = $this->columns;
        $this->module($r - 1, 0, $chr, 0);
        $this->module($r - 1, $c - 1, $chr, 1);
        $this->module(0, $c - 3, $chr, 2);
        $this->module(0, $c - 2, $chr, 3);
        $this->module(0, $c - 1, $chr, 4);
        $this->module(1, $c - 3, $chr, 5);
        $this->module(1, $c - 2, $chr, 6);
        $this->module(1, $c - 1, $chr, 7);
    }
}
