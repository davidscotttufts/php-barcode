<?php

declare(strict_types=1);

namespace PhpBarcode\Symbol;

use PhpBarcode\Renderer\RenderOptions;

/**
 * A two-dimensional barcode made of square modules (QR Code, Data Matrix,
 * Aztec, …) or a stacked code drawn row by row (PDF417).
 *
 * Every module is drawn moduleWidth pixels wide. Rows are moduleWidth pixels
 * tall multiplied by $rowHeight, which lets stacked codes such as PDF417 use
 * their customary taller rows.
 */
final readonly class MatrixSymbol implements Symbol
{
    /** @var non-empty-list<string> */
    private array $rows;

    /**
     * @param list<string> $rows           Rows of "1" (dark) and "0" (light), all the same length.
     * @param int          $rowHeight      Height of each row, in modules.
     * @param list<array{row: int, column: int, size: int}> $finderPatterns
     *        Square finder patterns ("eyes", e.g. QR's three 7×7 corners), so renderers can style them separately.
     */
    public function __construct(
        array $rows,
        private string $text,
        private string $symbology,
        private int $quietZone = 4,
        private int $rowHeight = 1,
        public array $finderPatterns = [],
    ) {
        if ($rows === []) {
            throw new \InvalidArgumentException('A matrix symbol needs at least one row.');
        }
        $width = strlen($rows[0]);
        foreach ($rows as $row) {
            if ($width === 0 || strlen($row) !== $width || strspn($row, '01') !== $width) {
                throw new \InvalidArgumentException('Matrix rows must be non-empty, equally long strings of 0 and 1.');
            }
        }
        if ($quietZone < 0 || $rowHeight < 1) {
            throw new \InvalidArgumentException('Invalid quiet zone or row height.');
        }

        $this->rows = array_values($rows);
    }

    /**
     * Builds a symbol from a grid of booleans (true = dark), indexed [row][column].
     *
     * @param list<list<bool|int>> $grid
     * @param list<array{row: int, column: int, size: int}> $finderPatterns
     */
    public static function fromGrid(array $grid, string $text, string $symbology, int $quietZone = 4, int $rowHeight = 1, array $finderPatterns = []): self
    {
        return new self(
            array_map(static fn(array $row): string => implode('', array_map(static fn(bool|int $dark): string => $dark ? '1' : '0', $row)), $grid),
            $text,
            $symbology,
            $quietZone,
            $rowHeight,
            $finderPatterns,
        );
    }

    public function text(): string
    {
        return $this->text;
    }

    /**
     * A copy with different human-readable text (the modules are unchanged).
     */
    public function withText(string $text): self
    {
        return new self($this->rows, $text, $this->symbology, $this->quietZone, $this->rowHeight, $this->finderPatterns);
    }

    public function symbology(): string
    {
        return $this->symbology;
    }

    public function quietZone(): int
    {
        return $this->quietZone;
    }

    public function width(): int
    {
        return strlen($this->rows[0]);
    }

    public function height(): int
    {
        return count($this->rows);
    }

    public function isDark(int $row, int $column): bool
    {
        return ($this->rows[$row][$column] ?? '0') === '1';
    }

    public function toGrid(): array
    {
        return $this->rows;
    }

    public function layout(RenderOptions $options): Layout
    {
        $quietZone = $options->quietZone ?? $this->quietZone;
        $module = $options->moduleWidth;
        $rowPixels = $module * $this->rowHeight;

        // Merge horizontal runs of dark modules into single rectangles to keep output small.
        $rects = [];
        foreach ($this->rows as $y => $row) {
            preg_match_all('/1+/', $row, $runs, PREG_OFFSET_CAPTURE);
            foreach ($runs[0] as [$run, $x]) {
                $rects[] = [
                    'x' => ($quietZone + $x) * $module,
                    'y' => $quietZone * $module + $y * $rowPixels,
                    'width' => strlen($run) * $module,
                    'height' => $rowPixels,
                ];
            }
        }

        $symbolHeight = 2 * $quietZone * $module + $this->height() * $rowPixels;
        $text = $options->showText && trim($this->text) !== '' ? $this->text : null;

        [$modules, $finders] = $this->rowHeight === 1 ? $this->styledGeometry($quietZone, $module) : [[], []];

        return new Layout(
            width: ($this->width() + 2 * $quietZone) * $module,
            height: $symbolHeight + ($text === null ? 0.0 : $options->textGap + $options->fontSize * 1.25),
            rects: $rects,
            text: $text,
            textTop: $symbolHeight + $options->textGap,
            fontSize: $options->fontSize,
            modules: $modules,
            finders: $finders,
            symbolBox: [
                'x' => $quietZone * $module,
                'y' => $quietZone * $module,
                'width' => $this->width() * $module,
                'height' => $this->height() * $rowPixels,
            ],
        );
    }

    /**
     * Individual dark modules outside the finder patterns, plus the finder boxes, in pixels.
     *
     * @return array{list<array{x: float, y: float, size: float}>, list<array{x: float, y: float, size: float}>}
     */
    private function styledGeometry(int $quietZone, float $module): array
    {
        $insideFinder = [];
        $finders = [];
        foreach ($this->finderPatterns as $finder) {
            if ($finder['size'] !== 7) {
                continue; // Only QR-style 7×7 finders (ring + center) can be restyled; others stay as modules.
            }
            for ($dy = 0; $dy < $finder['size']; $dy++) {
                for ($dx = 0; $dx < $finder['size']; $dx++) {
                    $insideFinder[($finder['row'] + $dy) . ',' . ($finder['column'] + $dx)] = true;
                }
            }
            $finders[] = [
                'x' => ($quietZone + $finder['column']) * $module,
                'y' => ($quietZone + $finder['row']) * $module,
                'size' => $finder['size'] * $module,
            ];
        }

        $modules = [];
        foreach ($this->rows as $y => $row) {
            for ($x = strpos($row, '1'); $x !== false; $x = strpos($row, '1', $x + 1)) {
                if (!isset($insideFinder[$y . ',' . $x])) {
                    $modules[] = ['x' => ($quietZone + $x) * $module, 'y' => ($quietZone + $y) * $module, 'size' => $module];
                }
            }
        }

        return [$modules, $finders];
    }

    /**
     * 2D codes default to larger modules and no caption.
     */
    public function defaultRenderOptions(): RenderOptions
    {
        return new RenderOptions(moduleWidth: 4.0, showText: false);
    }
}
