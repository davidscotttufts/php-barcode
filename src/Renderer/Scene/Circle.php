<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Scene;

use PhpBarcode\Renderer\Canvas\Canvas;

final readonly class Circle implements Shape
{
    public function __construct(
        public float $cx,
        public float $cy,
        public float $radius,
    ) {}

    public function drawOn(Canvas $canvas): void
    {
        $canvas->circle($this->cx, $this->cy, $this->radius);
    }

    public function rotatedClockwise(float $canvasHeight): self
    {
        return new self($canvasHeight - $this->cy, $this->cx, $this->radius);
    }
}
