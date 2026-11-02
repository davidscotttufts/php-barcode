<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Scene;

use PhpBarcode\Renderer\Canvas\Canvas;

final readonly class Text implements Shape
{
    /**
     * @param 'start'|'middle'|'end' $anchor
     * @param 0|90                   $rotation
     */
    public function __construct(
        public string $text,
        public float $x,
        public float $baseline,
        public float $size,
        public string $anchor = 'middle',
        public int $rotation = 0,
    ) {}

    public function drawOn(Canvas $canvas): void
    {
        $canvas->text($this->text, $this->x, $this->baseline, $this->size, $this->anchor, $this->rotation);
    }

    public function rotatedClockwise(float $canvasHeight): self
    {
        return new self($this->text, $canvasHeight - $this->baseline, $this->x, $this->size, $this->anchor, 90);
    }
}
