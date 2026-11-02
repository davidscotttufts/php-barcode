<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Scene;

use PhpBarcode\Renderer\Canvas\Canvas;

final readonly class Annulus implements Shape
{
    public function __construct(
        public float $cx,
        public float $cy,
        public float $outer,
        public float $inner = 0.0,
    ) {}

    public function drawOn(Canvas $canvas): void
    {
        $canvas->annulus($this->cx, $this->cy, $this->outer, $this->inner);
    }

    public function rotatedClockwise(float $canvasHeight): self
    {
        return new self($canvasHeight - $this->cy, $this->cx, $this->outer, $this->inner);
    }
}
