<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Scene;

use PhpBarcode\Renderer\Canvas\Canvas;
use PhpBarcode\Renderer\Style\Logo;

final readonly class Image implements Shape
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public Logo $logo,
    ) {}

    public function drawOn(Canvas $canvas): void
    {
        $canvas->image($this->x, $this->y, $this->width, $this->height, $this->logo);
    }

    /**
     * Logos stay upright; only their position follows the rotation.
     */
    public function rotatedClockwise(float $canvasHeight): self
    {
        $cx = $canvasHeight - ($this->y + $this->height / 2);
        $cy = $this->x + $this->width / 2;

        return new self($cx - $this->width / 2, $cy - $this->height / 2, $this->width, $this->height, $this->logo);
    }
}
