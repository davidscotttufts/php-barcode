<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Canvas;

use PhpBarcode\Renderer\Style\Logo;

/**
 * A drawing surface for one output format.
 *
 * Coordinates are pixels with the origin at the top left. Dark shapes are
 * filled with the foreground color or gradient; everything else is
 * background. Each output format implements these few primitives, and the
 * Scene does the rest.
 */
interface Canvas
{
    /**
     * A dark rectangle, optionally with rounded corners.
     */
    public function rect(float $x, float $y, float $width, float $height, float $radius = 0.0): void;

    public function circle(float $cx, float $cy, float $radius): void;

    /**
     * A dark square ring: a $size square with a $size - 2*$thickness hole.
     * $radius is the outer corner radius (the inner one scales with it).
     */
    public function ring(float $x, float $y, float $size, float $thickness, float $radius = 0.0): void;

    /**
     * A dark filled polygon.
     *
     * @param list<array{float, float}> $points
     */
    public function polygon(array $points): void;

    /**
     * A dark circular ring; an $inner radius of 0 draws a disc.
     */
    public function annulus(float $cx, float $cy, float $outer, float $inner): void;

    public function image(float $x, float $y, float $width, float $height, Logo $logo): void;

    /**
     * @param float                   $baseline Y of the text baseline (for rotated text, the X).
     * @param 'start'|'middle'|'end'  $anchor
     * @param 0|90                    $rotation Degrees clockwise.
     */
    public function text(string $text, float $x, float $baseline, float $size, string $anchor, int $rotation = 0): void;

    /**
     * Completes the drawing and returns the encoded output.
     */
    public function finish(): string;
}
