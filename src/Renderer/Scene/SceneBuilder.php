<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Scene;

use PhpBarcode\Renderer\Orientation;
use PhpBarcode\Renderer\RenderOptions;
use PhpBarcode\Renderer\Style\FinderShape;
use PhpBarcode\Renderer\Style\ModuleShape;
use PhpBarcode\Symbol\Layout;
use PhpBarcode\Symbol\Symbol;

/**
 * Turns a symbol's Layout plus style options into a Scene of shapes.
 *
 * Module shapes, finder styles and logos apply to symbols whose Layout
 * describes individual modules (2D codes); other symbols are drawn from
 * their rectangles unchanged.
 */
final class SceneBuilder
{
    public function build(Symbol $symbol, RenderOptions $options): Scene
    {
        $caption = $options->caption !== null && trim($options->caption) !== '' ? $options->caption : null;
        $layout = $caption === null
            ? $symbol->layout($options)
            : $symbol->layout($options->withoutText())->withCaption($caption, $options->textGap, $options->fontSize);
        $shapes = $this->isStyled($layout, $options)
            ? $this->styledShapes($layout, $options)
            : array_map(static fn(array $r): Shape => new Rect($r['x'], $r['y'], $r['width'], $r['height']), $layout->rects);

        foreach ($layout->polygons as $points) {
            $shapes[] = new Polygon($points);
        }
        foreach ($layout->annuli as $ring) {
            $shapes[] = new Annulus($ring['x'], $ring['y'], $ring['outer'], $ring['inner']);
        }

        if ($options->logo !== null && $layout->symbolBox !== null && !$options->logo->punchout) {
            $shapes[] = $this->logoImage($layout, $options);
        }

        foreach ($layout->allTexts() as $item) {
            $size = $item['anchor'] === 'middle' ? self::fitToWidth($item['text'], $item['size'], $layout->width) : $item['size'];
            $shapes[] = new Text($item['text'], $item['x'], $item['y'] + $size, $size, $item['anchor']);
        }

        $fillBox = $layout->symbolBox ?? ['x' => 0.0, 'y' => 0.0, 'width' => $layout->width, 'height' => $layout->height];

        if ($options->orientation === Orientation::Vertical) {
            $shapes = array_map(static fn(Shape $shape): Shape => $shape->rotatedClockwise($layout->height), $shapes);
            $fillBox = [
                'x' => $layout->height - $fillBox['y'] - $fillBox['height'],
                'y' => $fillBox['x'],
                'width' => $fillBox['height'],
                'height' => $fillBox['width'],
            ];

            return new Scene($layout->height, $layout->width, $shapes, $fillBox);
        }

        return new Scene($layout->width, $layout->height, $shapes, $fillBox);
    }

    /**
     * Shrinks a centered caption that would be wider than the symbol, assuming a
     * monospace font (advance ≈ 0.6 em), so long GS1 strings never get clipped.
     */
    private static function fitToWidth(string $text, float $size, float $width): float
    {
        $length = (int) preg_match_all('/./su', $text);
        $available = $width * 0.96;

        return $length > 0 && $length * 0.6 * $size > $available ? $available / (0.6 * $length) : $size;
    }

    private function isStyled(Layout $layout, RenderOptions $options): bool
    {
        if ($layout->modules === [] && $layout->finders === []) {
            return false;
        }

        return $options->moduleShape !== ModuleShape::Square
            || $options->finderShape !== FinderShape::Square
            || ($options->logo !== null && $options->logo->punchout);
    }

    /**
     * @return list<Shape>
     */
    private function styledShapes(Layout $layout, RenderOptions $options): array
    {
        $shapes = [];
        $logo = $options->logo !== null && $layout->symbolBox !== null ? $this->logoImage($layout, $options) : null;
        $hole = null;
        if ($logo !== null) {
            $margin = $logo->logo->margin * $options->moduleWidth;
            $hole = [$logo->x - $margin, $logo->y - $margin, $logo->x + $logo->width + $margin, $logo->y + $logo->height + $margin];
        }

        $radius = $options->moduleShape->cornerRadius();
        foreach ($layout->modules as $module) {
            $size = $module['size'];
            $cx = $module['x'] + $size / 2;
            $cy = $module['y'] + $size / 2;
            if ($hole !== null && $cx > $hole[0] && $cx < $hole[2] && $cy > $hole[1] && $cy < $hole[3]) {
                continue;
            }
            $shapes[] = match ($options->moduleShape) {
                ModuleShape::Dot => new Circle($cx, $cy, $size * 0.45),
                default => new Rect($module['x'], $module['y'], $size, $size, $size * $radius),
            };
        }

        $finderRadius = $options->finderShape->cornerRadius();
        foreach ($layout->finders as $finder) {
            // QR-style finder: 7 units across, a 1-unit ring around a 3-unit center.
            $unit = $finder['size'] / 7;
            $shapes[] = new Ring($finder['x'], $finder['y'], $finder['size'], $unit, $finder['size'] * $finderRadius);
            $shapes[] = $options->finderShape === FinderShape::Circle
                ? new Circle($finder['x'] + 3.5 * $unit, $finder['y'] + 3.5 * $unit, 1.5 * $unit)
                : new Rect($finder['x'] + 2 * $unit, $finder['y'] + 2 * $unit, 3 * $unit, 3 * $unit, 3 * $unit * $finderRadius);
        }

        if ($logo !== null) {
            $shapes[] = $logo;
        }

        return $shapes;
    }

    private function logoImage(Layout $layout, RenderOptions $options): Image
    {
        \assert($options->logo !== null && $layout->symbolBox !== null);
        $box = $layout->symbolBox;
        $width = $box['width'] * $options->logo->size;
        $height = $width / max(0.01, $options->logo->aspectRatio());

        return new Image(
            $box['x'] + ($box['width'] - $width) / 2,
            $box['y'] + ($box['height'] - $height) / 2,
            $width,
            $height,
            $options->logo,
        );
    }
}
