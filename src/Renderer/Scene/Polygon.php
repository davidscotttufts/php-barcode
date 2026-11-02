<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Scene;

use PhpBarcode\Renderer\Canvas\Canvas;

final readonly class Polygon implements Shape
{
    /**
     * @param list<array{float, float}> $points
     */
    public function __construct(
        public array $points,
    ) {}

    public function drawOn(Canvas $canvas): void
    {
        $canvas->polygon($this->points);
    }

    public function rotatedClockwise(float $canvasHeight): self
    {
        return new self(array_map(static fn(array $p): array => [$canvasHeight - $p[1], $p[0]], $this->points));
    }
}
