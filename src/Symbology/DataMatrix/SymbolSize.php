<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\DataMatrix;

/**
 * One Data Matrix ECC 200 symbol size and its capacity (ISO/IEC 16022 Table 7,
 * ISO/IEC 21471 for the DMRE rectangular extensions).
 */
final readonly class SymbolSize
{
    public int $verticalRegions;
    public int $horizontalRegions;

    /**
     * @param int $regionRows    Height of one data region, in modules (without finder/timing).
     * @param int $regionColumns Width of one data region, in modules.
     * @param int $blocks        Number of interleaved Reed–Solomon blocks.
     */
    public function __construct(
        public int $rows,
        public int $columns,
        public int $regionRows,
        public int $regionColumns,
        public int $dataCodewords,
        public int $ecCodewords,
        public int $blocks = 1,
        public bool $dmre = false,
    ) {
        $this->verticalRegions = intdiv($rows, $regionRows + 2);
        $this->horizontalRegions = intdiv($columns, $regionColumns + 2);
    }

    public function isSquare(): bool
    {
        return $this->rows === $this->columns;
    }

    public function name(): string
    {
        return $this->rows . 'x' . $this->columns;
    }

    /**
     * Rows of the mapping matrix (all data regions without finder/timing patterns).
     */
    public function mappingRows(): int
    {
        return $this->verticalRegions * $this->regionRows;
    }

    public function mappingColumns(): int
    {
        return $this->horizontalRegions * $this->regionColumns;
    }

    /**
     * Every ECC 200 size: 24 square, 6 rectangular and 18 DMRE rectangles.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            // Square
            new self(10, 10, 8, 8, 3, 5),
            new self(12, 12, 10, 10, 5, 7),
            new self(14, 14, 12, 12, 8, 10),
            new self(16, 16, 14, 14, 12, 12),
            new self(18, 18, 16, 16, 18, 14),
            new self(20, 20, 18, 18, 22, 18),
            new self(22, 22, 20, 20, 30, 20),
            new self(24, 24, 22, 22, 36, 24),
            new self(26, 26, 24, 24, 44, 28),
            new self(32, 32, 14, 14, 62, 36),
            new self(36, 36, 16, 16, 86, 42),
            new self(40, 40, 18, 18, 114, 48),
            new self(44, 44, 20, 20, 144, 56),
            new self(48, 48, 22, 22, 174, 68),
            new self(52, 52, 24, 24, 204, 84, 2),
            new self(64, 64, 14, 14, 280, 112, 2),
            new self(72, 72, 16, 16, 368, 144, 4),
            new self(80, 80, 18, 18, 456, 192, 4),
            new self(88, 88, 20, 20, 576, 224, 4),
            new self(96, 96, 22, 22, 696, 272, 4),
            new self(104, 104, 24, 24, 816, 336, 6),
            new self(120, 120, 18, 18, 1050, 408, 6),
            new self(132, 132, 20, 20, 1304, 496, 8),
            new self(144, 144, 22, 22, 1558, 620, 10),
            // Rectangular
            new self(8, 18, 6, 16, 5, 7),
            new self(8, 32, 6, 14, 10, 11),
            new self(12, 26, 10, 24, 16, 14),
            new self(12, 36, 10, 16, 22, 18),
            new self(16, 36, 14, 16, 32, 24),
            new self(16, 48, 14, 22, 49, 28),
            // DMRE (ISO/IEC 21471)
            new self(8, 48, 6, 22, 18, 15, 1, true),
            new self(8, 64, 6, 14, 24, 18, 1, true),
            new self(8, 80, 6, 18, 32, 22, 1, true),
            new self(8, 96, 6, 22, 38, 28, 1, true),
            new self(8, 120, 6, 18, 49, 32, 1, true),
            new self(8, 144, 6, 22, 63, 36, 1, true),
            new self(12, 64, 10, 14, 43, 27, 1, true),
            new self(12, 88, 10, 20, 64, 36, 1, true),
            new self(16, 64, 14, 14, 62, 36, 1, true),
            new self(20, 36, 18, 16, 44, 28, 1, true),
            new self(20, 44, 18, 20, 56, 34, 1, true),
            new self(20, 64, 18, 14, 84, 42, 1, true),
            new self(22, 48, 20, 22, 72, 38, 1, true),
            new self(24, 48, 22, 22, 80, 41, 1, true),
            new self(24, 64, 22, 14, 108, 46, 1, true),
            new self(26, 40, 24, 18, 70, 38, 1, true),
            new self(26, 48, 24, 22, 90, 42, 1, true),
            new self(26, 64, 24, 14, 118, 50, 1, true),
        ];
    }

    /**
     * Looks up a size by name, e.g. "16x48" (also accepts "16×48" and "16X48").
     */
    public static function fromName(string $name): self
    {
        if (preg_match('/^\s*(\d+)\s*[x×X*]\s*(\d+)\s*$/u', $name, $match) === 1) {
            foreach (self::all() as $size) {
                if ($size->rows === (int) $match[1] && $size->columns === (int) $match[2]) {
                    return $size;
                }
            }
        }

        throw new \InvalidArgumentException(sprintf('"%s" is not a Data Matrix ECC 200 size (rows x columns, e.g. "16x48").', $name));
    }
}
