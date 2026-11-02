<?php

declare(strict_types=1);

namespace PhpBarcode\Symbol;

use PhpBarcode\Renderer\RenderOptions;

/**
 * A symbol of hexagonal modules on an offset grid around a circular finder
 * (MaxiCode).
 *
 * Odd rows are offset half a module to the right. With module width W
 * (flat-to-flat), rows are W·√3/2 apart and the finder's dark rings span up
 * to 9W in diameter.
 *
 * Exact geometry is available from hexagons() and rings() for renderers
 * that can draw polygons and circles. layout() approximates both with
 * rectangles: each hexagon becomes the W × W·√3/2 cell it tiles, and the
 * rings become thin horizontal slices. That is accurate at the sampling
 * points scanners use, so the output decodes, but true hexagons look better.
 */
final readonly class HexagonSymbol implements Symbol
{
    /** Hexagons are drawn at this fraction of their tile, leaving thin light gaps between cells. */
    private const HEXAGON_SCALE = 0.92;

    /** Long (point-to-point) diameter of a hexagon, in module widths: 2/√3. */
    public const HEX_HEIGHT = 1.1547005383792515;

    /** Vertical distance between row centers, in module widths: √3/2. */
    public const ROW_PITCH = 0.8660254037844386;

    /** @var non-empty-list<string> */
    private array $rows;

    /**
     * @param list<string> $rows         Rows of "1"/"0", all the same length; odd rows are offset right.
     * @param int          $finderRow    Grid position the finder is centered on.
     * @param int          $finderColumn
     * @param list<array{inner: float, outer: float}> $rings Dark finder rings as radii, in module widths.
     */
    public function __construct(
        array $rows,
        private string $text,
        private string $symbology,
        private int $finderRow,
        private int $finderColumn,
        private array $rings,
        private int $quietZone = 1,
    ) {
        if ($rows === []) {
            throw new \InvalidArgumentException('A hexagon symbol needs at least one row.');
        }
        $width = strlen($rows[0]);
        foreach ($rows as $row) {
            if ($width === 0 || strlen($row) !== $width || strspn($row, '01') !== $width) {
                throw new \InvalidArgumentException('Rows must be non-empty, equally long strings of 0 and 1.');
            }
        }

        $this->rows = array_values($rows);
    }

    public function text(): string
    {
        return $this->text;
    }

    public function symbology(): string
    {
        return $this->symbology;
    }

    public function quietZone(): int
    {
        return $this->quietZone;
    }

    public function toGrid(): array
    {
        return $this->rows;
    }

    /**
     * Width of the symbol in module widths, excluding the quiet zone.
     */
    public function width(): float
    {
        return strlen($this->rows[0]) + 0.5;
    }

    /**
     * Height of the symbol in module widths, excluding the quiet zone.
     */
    public function height(): float
    {
        return self::HEX_HEIGHT + (count($this->rows) - 1) * self::ROW_PITCH;
    }

    /**
     * Centers of the dark hexagons, in module widths from the symbol's top-left corner.
     *
     * @return list<array{x: float, y: float}>
     */
    public function hexagons(): array
    {
        $centers = [];
        foreach ($this->rows as $y => $row) {
            $offset = $y % 2 === 1 ? 1.0 : 0.5;
            for ($x = 0, $width = strlen($row); $x < $width; $x++) {
                if ($row[$x] === '1') {
                    $centers[] = ['x' => $x + $offset, 'y' => self::HEX_HEIGHT / 2 + $y * self::ROW_PITCH];
                }
            }
        }

        return $centers;
    }

    /**
     * Finder center and dark ring radii, in module widths.
     *
     * @return array{x: float, y: float, rings: list<array{inner: float, outer: float}>}
     */
    public function finder(): array
    {
        return [
            'x' => $this->finderColumn + ($this->finderRow % 2 === 1 ? 1.0 : 0.5),
            'y' => self::HEX_HEIGHT / 2 + $this->finderRow * self::ROW_PITCH,
            'rings' => $this->rings,
        ];
    }

    public function layout(RenderOptions $options): Layout
    {
        $quiet = $options->quietZone ?? $this->quietZone;
        $module = $options->moduleWidth;
        $originX = $quiet * $module;
        $originY = $quiet * $module;

        // Pointy-topped hexagons: width = one module, so they tile at the MaxiCode row pitch.
        // Drawn slightly smaller than the tile, like printed MaxiCode, to keep the cells distinct.
        $halfWidth = 0.5 * $module * self::HEXAGON_SCALE;
        $radius = $module / sqrt(3) * self::HEXAGON_SCALE;

        $polygons = [];
        foreach ($this->hexagons() as $center) {
            $cx = $originX + $center['x'] * $module;
            $cy = $originY + $center['y'] * $module;
            $polygons[] = [
                [$cx, $cy - $radius],
                [$cx + $halfWidth, $cy - $radius / 2],
                [$cx + $halfWidth, $cy + $radius / 2],
                [$cx, $cy + $radius],
                [$cx - $halfWidth, $cy + $radius / 2],
                [$cx - $halfWidth, $cy - $radius / 2],
            ];
        }

        $finder = $this->finder();
        $annuli = [];
        foreach ($finder['rings'] as $ring) {
            $annuli[] = [
                'x' => $originX + $finder['x'] * $module,
                'y' => $originY + $finder['y'] * $module,
                'outer' => $ring['outer'] * $module,
                'inner' => $ring['inner'] * $module,
            ];
        }

        $symbolWidth = $this->width() * $module;
        $symbolHeight = $this->height() * $module;
        $canvasHeight = $symbolHeight + 2 * $quiet * $module;
        $text = $options->showText && trim($this->text) !== '' ? $this->text : null;

        return new Layout(
            width: $symbolWidth + 2 * $quiet * $module,
            height: $canvasHeight + ($text === null ? 0.0 : $options->textGap + $options->fontSize * 1.25),
            rects: [],
            text: $text,
            textTop: $canvasHeight + $options->textGap,
            fontSize: $options->fontSize,
            symbolBox: ['x' => $originX, 'y' => $originY, 'width' => $symbolWidth, 'height' => $symbolHeight],
            polygons: $polygons,
            annuli: $annuli,
        );
    }

    /**
     * MaxiCode is about one inch wide; default to 8 px modules and no caption.
     */
    public function defaultRenderOptions(): RenderOptions
    {
        return new RenderOptions(moduleWidth: 8.0, showText: false);
    }
}
