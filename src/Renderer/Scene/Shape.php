<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Scene;

use PhpBarcode\Renderer\Canvas\Canvas;

/**
 * A drawable element of a Scene.
 */
interface Shape
{
    public function drawOn(Canvas $canvas): void;

    /**
     * This shape after rotating the whole drawing 90° clockwise inside a canvas $canvasHeight tall.
     */
    public function rotatedClockwise(float $canvasHeight): self;
}
