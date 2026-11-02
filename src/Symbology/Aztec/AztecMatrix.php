<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\Aztec;

/**
 * Mutable square module grid used while drawing an Aztec symbol.
 *
 * @internal
 */
final class AztecMatrix
{
    /** @var array<int, array<int, bool>> [row][column] */
    private array $modules;

    public bool $compact = true;

    public int $layers = 0;

    public function __construct(
        public readonly int $size,
    ) {
        $this->modules = array_fill(0, $size, array_fill(0, $size, false));
    }

    /**
     * Darkens the module at column $x, row $y.
     */
    public function set(int $x, int $y): void
    {
        $this->modules[$y][$x] = true;
    }

    public function get(int $x, int $y): bool
    {
        return $this->modules[$y][$x];
    }

    /**
     * @return list<list<bool>>
     */
    public function toGrid(): array
    {
        return array_values(array_map(array_values(...), $this->modules));
    }

    /**
     * The bullseye finder pattern, excluding the orientation marks.
     *
     * @return array{row: int, column: int, size: int}
     */
    public function bullseye(): array
    {
        $radius = $this->compact ? 4 : 6;
        $center = intdiv($this->size, 2);

        return ['row' => $center - $radius, 'column' => $center - $radius, 'size' => 2 * $radius + 1];
    }
}
