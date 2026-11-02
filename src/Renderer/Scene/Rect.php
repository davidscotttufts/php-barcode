<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Scene;

use PhpBarcode\Renderer\Canvas\Canvas;

final readonly class Rect implements Shape
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public float $radius = 0.0,
    ) {}

    public function drawOn(Canvas $canvas): void
    {
        $canvas->rect($this->x, $this->y, $this->width, $this->height, $this->radius);
    }

    public function rotatedClockwise(float $canvasHeight): self
    {
        return new self($canvasHeight - $this->y - $this->height, $this->x, $this->height, $this->width, $this->radius);
    }
}
