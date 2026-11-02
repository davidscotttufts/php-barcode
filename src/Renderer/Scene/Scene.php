<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Scene;

use PhpBarcode\Renderer\Canvas\Canvas;

/**
 * Everything to draw for one symbol, in final (possibly rotated) coordinates.
 */
final readonly class Scene
{
    /**
     * @param list<Shape> $shapes
     * @param array{x: float, y: float, width: float, height: float} $fillBox Area a gradient spans.
     */
    public function __construct(
        public float $width,
        public float $height,
        public array $shapes,
        public array $fillBox,
    ) {}

    public function drawOn(Canvas $canvas): string
    {
        foreach ($this->shapes as $shape) {
            $shape->drawOn($canvas);
        }

        return $canvas->finish();
    }
}
