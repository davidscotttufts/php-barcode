<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\QrCode;

/**
 * Data mask patterns and the ISO/IEC 18004 mask penalty score.
 *
 * @internal
 */
final class Masks
{
    /**
     * The QR mask condition: true means the module is flipped. x = column, y = row.
     *
     * @return \Closure(int, int): bool
     */
    public static function qr(int $pattern): \Closure
    {
        return match ($pattern) {
            0 => static fn(int $x, int $y): bool => ($x + $y) % 2 === 0,
            1 => static fn(int $x, int $y): bool => $y % 2 === 0,
            2 => static fn(int $x, int $y): bool => $x % 3 === 0,
            3 => static fn(int $x, int $y): bool => ($x + $y) % 3 === 0,
            4 => static fn(int $x, int $y): bool => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
            5 => static fn(int $x, int $y): bool => ($x * $y) % 2 + ($x * $y) % 3 === 0,
            6 => static fn(int $x, int $y): bool => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
            7 => static fn(int $x, int $y): bool => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
            default => throw new \InvalidArgumentException('QR mask patterns are numbered 0-7.'),
        };
    }

    /**
     * Penalty score of a finished symbol; the encoder picks the lowest.
     */
    public static function penalty(ModuleGrid $grid): int
    {
        $rows = $grid->rows();
        $columns = $grid->columns();
        $penalty = 0;

        foreach ([...$rows, ...$columns] as $line) {
            // Rule 1: runs of five or more same-colored modules.
            preg_match_all('/0{5,}|1{5,}/', $line, $runs);
            foreach ($runs[0] as $run) {
                $penalty += strlen($run) - 2;
            }

            // Rule 3: finder-like 1:1:3:1:1 patterns with four light modules on one side.
            $penalty += 40 * (int) preg_match_all('/(?=10111010000|00001011101)/', $line);
        }

        // Rule 2: 2×2 blocks of the same color.
        for ($y = 0, $height = count($rows) - 1; $y < $height; $y++) {
            $top = $rows[$y];
            $bottom = $rows[$y + 1];
            for ($x = 0, $width = strlen($top) - 1; $x < $width; $x++) {
                $color = $top[$x];
                if ($top[$x + 1] === $color && $bottom[$x] === $color && $bottom[$x + 1] === $color) {
                    $penalty += 3;
                }
            }
        }

        // Rule 4: deviation of the dark-module share from 50%, in 5% steps.
        $total = $grid->width * $grid->height;
        $dark = substr_count(implode('', $rows), '1');
        $penalty += intdiv(abs($dark * 2 - $total) * 10, $total) * 10;

        return $penalty;
    }
}
