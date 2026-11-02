<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Style;

use PhpBarcode\Renderer\Color;

/**
 * A two-color gradient used to fill the dark modules.
 *
 * SVG and raster output render it exactly. PDF and EPS output fall back to
 * the start color.
 */
final readonly class Gradient
{
    /**
     * @param float $angle Direction of a linear gradient in degrees; 0 runs left to right, 90 top to bottom.
     */
    public function __construct(
        public Color $from,
        public Color $to,
        public GradientType $type = GradientType::Linear,
        public float $angle = 45.0,
    ) {}

    public static function linear(Color|string $from, Color|string $to, float $angle = 45.0): self
    {
        return new self(self::color($from), self::color($to), GradientType::Linear, $angle);
    }

    public static function radial(Color|string $from, Color|string $to): self
    {
        return new self(self::color($from), self::color($to), GradientType::Radial);
    }

    /**
     * Color at position $t (0..1) along the gradient.
     */
    public function at(float $t): Color
    {
        $t = max(0.0, min(1.0, $t));
        $mix = static fn(int $a, int $b): int => max(0, min(255, (int) round($a + ($b - $a) * $t)));

        return new Color(
            $mix($this->from->red, $this->to->red),
            $mix($this->from->green, $this->to->green),
            $mix($this->from->blue, $this->to->blue),
            $this->from->alpha + ($this->to->alpha - $this->from->alpha) * $t,
        );
    }

    /**
     * Gradient position (0..1) of a point inside a box.
     */
    public function positionIn(float $x, float $y, float $boxX, float $boxY, float $boxWidth, float $boxHeight): float
    {
        $u = $boxWidth > 0 ? ($x - $boxX) / $boxWidth : 0.0;
        $v = $boxHeight > 0 ? ($y - $boxY) / $boxHeight : 0.0;

        if ($this->type === GradientType::Radial) {
            return min(1.0, sqrt(($u - 0.5) ** 2 + ($v - 0.5) ** 2) / sqrt(0.5));
        }

        [$dx, $dy] = $this->direction();
        // Project onto the direction vector, normalized so the box spans exactly 0..1.
        $min = min(0.0, $dx) + min(0.0, $dy);
        $max = max(0.0, $dx) + max(0.0, $dy);

        return $max > $min ? ($u * $dx + $v * $dy - $min) / ($max - $min) : 0.0;
    }

    /**
     * Unit direction vector of a linear gradient.
     *
     * @return array{float, float}
     */
    public function direction(): array
    {
        $radians = deg2rad($this->angle);

        return [round(cos($radians), 6), round(sin($radians), 6)];
    }

    private static function color(Color|string $color): Color
    {
        return is_string($color) ? Color::fromHex($color) : $color;
    }
}
