<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Scene;

use PhpBarcode\Renderer\Canvas\Canvas;

final readonly class Ring implements Shape
{
    public function __construct(
        public float $x,
        public float $y,
        public float $size,
        public float $thickness,
        public float $radius = 0.0,
    ) {}

    public function drawOn(Canvas $canvas): void
    {
        $canvas->ring($this->x, $this->y, $this->size, $this->thickness, $this->radius);
    }

    public function rotatedClockwise(float $canvasHeight): self
    {
        return new self($canvasHeight - $this->y - $this->size, $this->x, $this->size, $this->thickness, $this->radius);
    }
}
