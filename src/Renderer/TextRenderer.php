<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer;

use PhpBarcode\Symbol\MatrixSymbol;
use PhpBarcode\Symbol\Symbol;

/**
 * Draws a symbol with Unicode block characters, for terminals, logs and tests.
 *
 * Linear barcodes use one column per module, repeated over $rows lines.
 * Two-dimensional symbols pack two module rows into each line with half-block
 * characters, so a QR code printed in a terminal is still scannable.
 * Pixel sizes and colors are ignored; quiet zone, text and orientation apply.
 */
final class TextRenderer implements RendererInterface
{
    public function __construct(
        private readonly int $rows = 4,
    ) {
        if ($rows < 1) {
            throw new \InvalidArgumentException('The text renderer needs at least one row.');
        }
    }

    public function render(Symbol $symbol, RenderOptions $options): string
    {
        $grid = $symbol->toGrid();
        $isLinear = count($grid) === 1;
        $quiet = $options->quietZone ?? $symbol->quietZone();

        if ($isLinear) {
            $grid = array_fill(0, $this->rows, $grid[0]);
        }

        // Only true 2D codes need a vertical quiet zone to scan.
        $grid = self::pad($grid, $quiet, $symbol instanceof MatrixSymbol ? $quiet : 0);

        if ($options->orientation === Orientation::Vertical) {
            $grid = self::rotateClockwise($grid);
        }

        $lines = $isLinear && $options->orientation === Orientation::Horizontal
            ? array_map(static fn(string $row): string => strtr($row, ['1' => '█', '0' => ' ']), $grid)
            : self::halfBlocks($grid);

        $caption = $options->caption ?? ($options->showText ? $symbol->text() : '');
        if ($options->orientation === Orientation::Horizontal && trim($caption) !== '') {
            $width = strlen($grid[0]);
            $length = function_exists('mb_strlen') ? mb_strlen($caption) : strlen($caption);
            $lines[] = str_repeat(' ', max(0, intdiv($width - $length, 2))) . $caption;
        }

        return implode("\n", $lines) . "\n";
    }

    public function mimeType(): string
    {
        return 'text/plain; charset=utf-8';
    }

    public function extension(): string
    {
        return 'txt';
    }

    /**
     * @param list<string> $grid
     *
     * @return list<string>
     */
    private static function pad(array $grid, int $horizontal, int $vertical): array
    {
        $side = str_repeat('0', $horizontal);
        $grid = array_map(static fn(string $row): string => $side . $row . $side, $grid);
        $blank = array_fill(0, $vertical, str_repeat('0', strlen($grid[0] ?? '')));

        return [...$blank, ...$grid, ...$blank];
    }

    /**
     * @param list<string> $grid
     *
     * @return list<string>
     */
    private static function rotateClockwise(array $grid): array
    {
        $rotated = [];
        $height = count($grid);
        for ($x = 0, $width = strlen($grid[0] ?? ''); $x < $width; $x++) {
            $row = '';
            for ($y = $height - 1; $y >= 0; $y--) {
                $row .= $grid[$y][$x];
            }
            $rotated[] = $row;
        }

        return $rotated;
    }

    /**
     * Two module rows per text line: ▀ upper, ▄ lower, █ both.
     *
     * @param list<string> $grid
     *
     * @return list<string>
     */
    private static function halfBlocks(array $grid): array
    {
        $lines = [];
        for ($y = 0, $height = count($grid); $y < $height; $y += 2) {
            $upper = $grid[$y];
            $lower = $grid[$y + 1] ?? str_repeat('0', strlen($upper));
            $line = '';
            for ($x = 0, $width = strlen($upper); $x < $width; $x++) {
                $line .= match ($upper[$x] . $lower[$x]) {
                    '11' => '█',
                    '10' => '▀',
                    '01' => '▄',
                    default => ' ',
                };
            }
            $lines[] = $line;
        }

        return $lines;
    }
}
