<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\QrCode;

/**
 * Mutable module grid used while building QR-family symbols.
 *
 * Tracks which modules belong to function patterns (finders, timing,
 * format information, …) so data placement and masking skip them.
 *
 * @internal
 */
final class ModuleGrid
{
    /** @var array<int, array<int, int>> [y][x], 1 = dark */
    private array $dark;

    /** @var array<int, array<int, bool>> [y][x], true = function pattern */
    private array $function;

    public function __construct(
        public readonly int $width,
        public readonly int $height,
    ) {
        $this->dark = array_fill(0, $height, array_fill(0, $width, 0));
        $this->function = array_fill(0, $height, array_fill(0, $width, false));
    }

    public function setFunction(int $x, int $y, bool $dark): void
    {
        $this->dark[$y][$x] = $dark ? 1 : 0;
        $this->function[$y][$x] = true;
    }

    public function isFunction(int $x, int $y): bool
    {
        return $this->function[$y][$x];
    }

    public function isDark(int $x, int $y): bool
    {
        return $this->dark[$y][$x] === 1;
    }

    /**
     * Draws a finder pattern (7×7 with its light separator ring) centred on ($cx, $cy).
     */
    public function drawFinder(int $cx, int $cy): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $cx + $dx;
                $y = $cy + $dy;
                if ($x >= 0 && $x < $this->width && $y >= 0 && $y < $this->height) {
                    $distance = max(abs($dx), abs($dy));
                    $this->setFunction($x, $y, $distance !== 2 && $distance !== 4);
                }
            }
        }
    }

    /**
     * Draws a 5×5 alignment pattern centred on ($cx, $cy).
     */
    public function drawAlignment(int $cx, int $cy): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    /**
     * Places a bit string in the two-column zigzag used by QR and Micro QR,
     * starting at the bottom-right corner. Remaining modules stay light.
     *
     * @param int|null $skipColumn  A vertical timing column the zigzag jumps over (6 for QR).
     * @param int|null $startColumn Right-hand column of the first pair (default: the right edge).
     */
    public function placeData(string $bits, ?int $skipColumn = null, ?int $startColumn = null): void
    {
        $length = strlen($bits);
        $index = 0;
        $start = $startColumn ?? $this->width - 1;

        for ($right = $start; $right >= 1; $right -= 2) {
            if ($right === $skipColumn) {
                $right--;
            }
            $upward = (($right + 1) & 2) === 0;
            if ($skipColumn === null) {
                // Without a skipped column, pairs alternate direction starting upward.
                $upward = intdiv($start - $right, 2) % 2 === 0;
            }
            for ($vertical = 0; $vertical < $this->height; $vertical++) {
                $y = $upward ? $this->height - 1 - $vertical : $vertical;
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    if (!$this->function[$y][$x]) {
                        $this->dark[$y][$x] = $index < $length && $bits[$index] === '1' ? 1 : 0;
                        $index++;
                    }
                }
            }
        }
    }

    /**
     * A copy with $mask applied to every non-function module.
     *
     * @param \Closure(int, int): bool $mask Receives (x, y); true flips the module.
     */
    public function masked(\Closure $mask): self
    {
        $copy = clone $this;
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                if (!$this->function[$y][$x] && $mask($x, $y)) {
                    $copy->dark[$y][$x] ^= 1;
                }
            }
        }

        return $copy;
    }

    /**
     * @return list<string>
     */
    public function rows(): array
    {
        return array_values(array_map(static fn(array $row): string => implode('', $row), $this->dark));
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        $columns = [];
        for ($x = 0; $x < $this->width; $x++) {
            $columns[] = implode('', array_column($this->dark, $x));
        }

        return $columns;
    }
}
