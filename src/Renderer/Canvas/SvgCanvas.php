<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Canvas;

use PhpBarcode\Renderer\Color;
use PhpBarcode\Renderer\RenderOptions;
use PhpBarcode\Renderer\Style\Gradient;
use PhpBarcode\Renderer\Style\GradientType;
use PhpBarcode\Renderer\Style\Logo;

/**
 * Builds an SVG document. Every dark shape goes into one <path>, so files stay
 * small; ring holes use reversed winding under the nonzero fill rule.
 */
final class SvgCanvas implements Canvas
{
    private string $path = '';

    /** @var list<string> */
    private array $overlays = [];

    /**
     * @param array{x: float, y: float, width: float, height: float} $fillBox
     */
    public function __construct(
        private readonly float $width,
        private readonly float $height,
        private readonly RenderOptions $options,
        private readonly array $fillBox,
        private readonly string $title,
        private readonly string $fontFamily,
        private readonly bool $xmlDeclaration,
    ) {}

    public function rect(float $x, float $y, float $width, float $height, float $radius = 0.0): void
    {
        $this->path .= self::roundedRect($x, $y, $width, $height, $radius, false);
    }

    public function circle(float $cx, float $cy, float $radius): void
    {
        $this->path .= sprintf(
            'M%s %sa%3$s %3$s 0 1 0 %4$s 0a%3$s %3$s 0 1 0 -%4$s 0z',
            self::n($cx - $radius),
            self::n($cy),
            self::n($radius),
            self::n(2 * $radius),
        );
    }

    public function ring(float $x, float $y, float $size, float $thickness, float $radius = 0.0): void
    {
        $inner = $size - 2 * $thickness;
        $this->path .= self::roundedRect($x, $y, $size, $size, $radius, false)
            . self::roundedRect($x + $thickness, $y + $thickness, $inner, $inner, $size > 0 ? $radius * $inner / $size : 0.0, true);
    }

    public function polygon(array $points): void
    {
        $this->path .= 'M' . implode('L', array_map(static fn(array $p): string => self::n($p[0]) . ' ' . self::n($p[1]), $points)) . 'z';
    }

    public function annulus(float $cx, float $cy, float $outer, float $inner): void
    {
        $this->circle($cx, $cy, $outer);
        if ($inner > 0) {
            // Same circle traversed the other way cuts a hole under the nonzero rule.
            $this->path .= sprintf(
                'M%s %sa%3$s %3$s 0 1 1 %4$s 0a%3$s %3$s 0 1 1 -%4$s 0z',
                self::n($cx - $inner),
                self::n($cy),
                self::n($inner),
                self::n(2 * $inner),
            );
        }
    }

    public function image(float $x, float $y, float $width, float $height, Logo $logo): void
    {
        $this->overlays[] = sprintf(
            '<image x="%s" y="%s" width="%s" height="%s" preserveAspectRatio="xMidYMid meet" href="data:%s;base64,%s"/>',
            self::n($x),
            self::n($y),
            self::n($width),
            self::n($height),
            $logo->mimeType,
            base64_encode($logo->data),
        );
    }

    public function text(string $text, float $x, float $baseline, float $size, string $anchor, int $rotation = 0): void
    {
        $this->overlays[] = sprintf(
            '<text x="%1$s" y="%2$s"%3$s text-anchor="%4$s" font-family="%5$s" font-size="%6$s"%7$s>%8$s</text>',
            self::n($x),
            self::n($baseline),
            $rotation === 0 ? '' : sprintf(' transform="rotate(%d %s %s)"', $rotation, self::n($x), self::n($baseline)),
            $anchor,
            self::escape($this->fontFamily),
            self::n($size),
            $this->options->gradient === null ? self::fill($this->options->foreground) : self::fill($this->options->gradient->from),
            self::escape($text),
        );
    }

    public function finish(): string
    {
        $dpi = $this->options->dpi;
        [$width, $height] = $dpi === null
            ? [self::n($this->width), self::n($this->height)]
            : [self::n($this->width / $dpi * 25.4) . 'mm', self::n($this->height / $dpi * 25.4) . 'mm'];

        $defs = '';
        $fill = self::fill($this->options->foreground);
        if ($this->options->gradient !== null) {
            $defs = '<defs>' . $this->gradientDefinition($this->options->gradient) . '</defs>';
            $fill = ' fill="url(#barcode-fill)"';
        }

        $background = $this->options->background->alpha > 0.0
            ? sprintf('<rect width="100%%" height="100%%"%s/>', self::fill($this->options->background))
            : '';

        return ($this->xmlDeclaration ? '<?xml version="1.0" encoding="UTF-8"?>' . "\n" : '')
            . sprintf(
                '<svg xmlns="http://www.w3.org/2000/svg" width="%s" height="%s" viewBox="0 0 %s %s"%s role="img" aria-label="%s">',
                $width,
                $height,
                self::n($this->width),
                self::n($this->height),
                $this->hasCurves() ? '' : ' shape-rendering="crispEdges"',
                self::escape($this->title),
            )
            . sprintf('<title>%s</title>', self::escape($this->title))
            . $defs
            . $background
            . ($this->path === '' ? '' : sprintf('<path d="%s"%s/>', $this->path, $fill))
            . implode('', $this->overlays)
            . '</svg>';
    }

    private function hasCurves(): bool
    {
        return str_contains($this->path, 'a') || str_contains($this->path, 'L');
    }

    private function gradientDefinition(Gradient $gradient): string
    {
        $box = $this->fillBox;
        $stops = sprintf(
            '<stop offset="0"%s/><stop offset="1"%s/>',
            self::stop($gradient->from),
            self::stop($gradient->to),
        );

        if ($gradient->type === GradientType::Radial) {
            return sprintf(
                '<radialGradient id="barcode-fill" gradientUnits="userSpaceOnUse" cx="%s" cy="%s" r="%s">%s</radialGradient>',
                self::n($box['x'] + $box['width'] / 2),
                self::n($box['y'] + $box['height'] / 2),
                self::n(hypot($box['width'], $box['height']) / 2),
                $stops,
            );
        }

        [$dx, $dy] = $gradient->direction();
        $half = (abs($box['width'] * $dx) + abs($box['height'] * $dy)) / 2;
        $cx = $box['x'] + $box['width'] / 2;
        $cy = $box['y'] + $box['height'] / 2;

        return sprintf(
            '<linearGradient id="barcode-fill" gradientUnits="userSpaceOnUse" x1="%s" y1="%s" x2="%s" y2="%s">%s</linearGradient>',
            self::n($cx - $dx * $half),
            self::n($cy - $dy * $half),
            self::n($cx + $dx * $half),
            self::n($cy + $dy * $half),
            $stops,
        );
    }

    /**
     * Path for a (rounded) rectangle; $reverse winds counter-clockwise to cut a hole.
     */
    private static function roundedRect(float $x, float $y, float $w, float $h, float $r, bool $reverse): string
    {
        $r = max(0.0, min($r, $w / 2, $h / 2));

        if ($r === 0.0) {
            return $reverse
                ? sprintf('M%s %sv%sh%sv-%sz', self::n($x), self::n($y), self::n($h), self::n($w), self::n($h))
                : sprintf('M%s %sh%sv%sh-%sz', self::n($x), self::n($y), self::n($w), self::n($h), self::n($w));
        }

        [$rs, $iw, $ih] = [self::n($r), self::n($w - 2 * $r), self::n($h - 2 * $r)];

        return $reverse
            ? sprintf('M%s %sa%3$s %3$s 0 0 0 -%3$s %3$sv%5$sa%3$s %3$s 0 0 0 %3$s %3$sh%4$sa%3$s %3$s 0 0 0 %3$s -%3$sv-%5$sa%3$s %3$s 0 0 0 -%3$s -%3$sz', self::n($x + $r), self::n($y), $rs, $iw, $ih)
            : sprintf('M%s %sh%4$sa%3$s %3$s 0 0 1 %3$s %3$sv%5$sa%3$s %3$s 0 0 1 -%3$s %3$sh-%4$sa%3$s %3$s 0 0 1 -%3$s -%3$sv-%5$sa%3$s %3$s 0 0 1 %3$s -%3$sz', self::n($x + $r), self::n($y), $rs, $iw, $ih);
    }

    private static function fill(Color $color): string
    {
        return sprintf(' fill="%s"', $color->toHex())
            . ($color->isOpaque() ? '' : sprintf(' fill-opacity="%s"', self::n($color->alpha)));
    }

    private static function stop(Color $color): string
    {
        return sprintf(' stop-color="%s"', $color->toHex())
            . ($color->isOpaque() ? '' : sprintf(' stop-opacity="%s"', self::n($color->alpha)));
    }

    /**
     * Formats a number compactly: 12.0 → "12", 2.50 → "2.5".
     */
    private static function n(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
