<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Canvas;

use PhpBarcode\Exception\RenderException;
use PhpBarcode\Renderer\Color;
use PhpBarcode\Renderer\RenderOptions;
use PhpBarcode\Renderer\Style\Logo;

/**
 * Shared geometry for page-description outputs (PDF, EPS).
 *
 * Works in points (1/72 inch) with the origin at the bottom left, converting
 * from pixels using the options' DPI. All dark shapes become one path filled
 * once; holes use reversed winding. Text uses the built-in Courier font, whose
 * fixed advance (0.6 em) allows exact centering without font metrics.
 * Gradients fall back to their start color.
 */
abstract class AbstractVectorCanvas implements Canvas
{
    private const KAPPA = 0.5522847498;

    protected readonly float $scale;

    /** Path operators for all dark shapes. */
    protected string $path = '';

    /** Content drawn after the dark shapes (images, text). */
    protected string $overlay = '';

    public function __construct(
        protected readonly float $width,
        protected readonly float $height,
        protected readonly RenderOptions $options,
        protected readonly string $title,
    ) {
        $this->scale = 72 / $options->effectiveDpi();
    }

    abstract protected function moveTo(float $x, float $y): string;

    abstract protected function lineTo(float $x, float $y): string;

    abstract protected function curveTo(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): string;

    abstract protected function closePath(): string;

    public function rect(float $x, float $y, float $width, float $height, float $radius = 0.0): void
    {
        $this->path .= $this->roundedRect($x, $y, $width, $height, $radius, false);
    }

    public function circle(float $cx, float $cy, float $radius): void
    {
        $this->path .= $this->roundedRect($cx - $radius, $cy - $radius, 2 * $radius, 2 * $radius, $radius, false);
    }

    public function ring(float $x, float $y, float $size, float $thickness, float $radius = 0.0): void
    {
        $inner = $size - 2 * $thickness;
        $this->path .= $this->roundedRect($x, $y, $size, $size, $radius, false)
            . $this->roundedRect($x + $thickness, $y + $thickness, $inner, $inner, $size > 0 ? $radius * $inner / $size : 0.0, true);
    }

    public function polygon(array $points): void
    {
        foreach ($points as $index => [$x, $y]) {
            $this->path .= $index === 0 ? $this->moveTo($this->px($x), $this->py($y)) : $this->lineTo($this->px($x), $this->py($y));
        }
        $this->path .= $this->closePath();
    }

    public function annulus(float $cx, float $cy, float $outer, float $inner): void
    {
        $this->path .= $this->roundedRect($cx - $outer, $cy - $outer, 2 * $outer, 2 * $outer, $outer, false);
        if ($inner > 0) {
            $this->path .= $this->roundedRect($cx - $inner, $cy - $inner, 2 * $inner, 2 * $inner, $inner, true);
        }
    }

    /**
     * Page width in points.
     */
    protected function pageWidth(): float
    {
        return $this->width * $this->scale;
    }

    protected function pageHeight(): float
    {
        return $this->height * $this->scale;
    }

    protected function px(float $x): float
    {
        return $x * $this->scale;
    }

    protected function py(float $y): float
    {
        return ($this->height - $y) * $this->scale;
    }

    protected function ink(): Color
    {
        return $this->options->gradient->from ?? $this->options->foreground;
    }

    /**
     * Horizontal distance from a text's anchor point to where drawing starts, in points.
     */
    protected function anchorOffset(string $text, float $sizePoints, string $anchor): float
    {
        $advance = strlen($text) * 0.6 * $sizePoints;

        return match ($anchor) {
            'middle' => $advance / 2,
            'end' => $advance,
            default => 0.0,
        };
    }

    /**
     * Text in the Windows-1252 encoding used by the standard PDF/PostScript fonts.
     */
    protected static function winAnsi(string $text): string
    {
        $converted = function_exists('iconv') ? @iconv('UTF-8', 'CP1252//TRANSLIT', $text) : false;

        return $converted === false ? (string) preg_replace('/[^\x20-\x7E]/', '?', $text) : $converted;
    }

    /**
     * Escapes a string literal for PDF and PostScript: ( ) and \.
     */
    protected static function literal(string $text): string
    {
        return '(' . strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r', "\n" => '\\n']) . ')';
    }

    protected static function n(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }

    protected static function rgb(Color $color): string
    {
        return sprintf('%s %s %s', self::n($color->red / 255), self::n($color->green / 255), self::n($color->blue / 255));
    }

    /**
     * Decodes a raster logo into RGB bytes and an 8-bit alpha channel, top row first.
     *
     * @return array{width: int, height: int, rgb: string, alpha: string, opaque: bool}
     */
    protected static function decodeLogo(Logo $logo): array
    {
        if ($logo->mimeType === 'image/svg+xml') {
            throw new RenderException('SVG logos can only be used with SVG output; supply a PNG, JPEG, GIF or WebP logo.');
        }
        if (!extension_loaded('gd')) {
            throw RenderException::missingExtension('gd', 'logos in PDF and EPS output');
        }

        $image = @imagecreatefromstring($logo->data)
            ?: throw new RenderException('GD could not decode the logo image.');
        $width = imagesx($image);
        $height = imagesy($image);

        $rgb = '';
        $alpha = '';
        $opaque = true;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $index = imagecolorat($image, $x, $y);
                if ($index === false) {
                    throw new RenderException('GD could not read the logo pixels.');
                }
                $c = imagecolorsforindex($image, $index);
                $rgb .= chr($c['red']) . chr($c['green']) . chr($c['blue']);
                $a = max(0, min(255, 255 - (int) round($c['alpha'] * 255 / 127)));
                $alpha .= chr($a);
                $opaque = $opaque && $a === 255;
            }
        }

        return ['width' => $width, 'height' => $height, 'rgb' => $rgb, 'alpha' => $alpha, 'opaque' => $opaque];
    }

    /**
     * A (rounded) rectangle as path operators; $reverse winds the other way to cut a hole.
     */
    private function roundedRect(float $x, float $y, float $w, float $h, float $r, bool $reverse): string
    {
        $r = max(0.0, min($r, $w / 2, $h / 2));
        $k = $r * self::KAPPA;
        [$l, $t, $rt, $b] = [$x, $y, $x + $w, $y + $h];

        // Corners in screen coordinates, clockwise from the top edge.
        $segments = [
            [$this->lineTo(...), [$rt - $r, $t]],
            [$this->curveTo(...), [$rt - $r + $k, $t, $rt, $t + $r - $k, $rt, $t + $r]],
            [$this->lineTo(...), [$rt, $b - $r]],
            [$this->curveTo(...), [$rt, $b - $r + $k, $rt - $r + $k, $b, $rt - $r, $b]],
            [$this->lineTo(...), [$l + $r, $b]],
            [$this->curveTo(...), [$l + $r - $k, $b, $l, $b - $r + $k, $l, $b - $r]],
            [$this->lineTo(...), [$l, $t + $r]],
            [$this->curveTo(...), [$l, $t + $r - $k, $l + $r - $k, $t, $l + $r, $t]],
        ];

        if ($r === 0.0) {
            $segments = array_values(array_filter($segments, fn(array $s): bool => count($s[1]) === 2));
        }

        if ($reverse) {
            return $this->reversed($l + $r, $t, $segments);
        }

        $ops = $this->moveTo($this->px($l + $r), $this->py($t));
        foreach ($segments as [$op, $points]) {
            $ops .= $op(...$this->toPage($points));
        }

        return $ops . $this->closePath();
    }

    /**
     * The same outline traversed backwards.
     *
     * @param list<array{\Closure, list<float>}> $segments
     */
    private function reversed(float $startX, float $startY, array $segments): string
    {
        // Rebuild the vertex list: each segment ends at its last point.
        $vertices = [[$startX, $startY]];
        foreach ($segments as [, $points]) {
            $vertices[] = array_slice($points, -2);
        }

        $ops = $this->moveTo($this->px($startX), $this->py($startY));
        for ($i = count($segments) - 1; $i >= 0; $i--) {
            [$op, $points] = $segments[$i];
            $target = $vertices[$i];
            if (count($points) === 6) {
                $ops .= $this->curveTo(...$this->toPage([$points[2], $points[3], $points[0], $points[1], $target[0], $target[1]]));
            } else {
                $ops .= $this->lineTo(...$this->toPage($target));
            }
        }

        return $ops . $this->closePath();
    }

    /**
     * @param list<float> $points Alternating screen x, y.
     *
     * @return list<float>
     */
    private function toPage(array $points): array
    {
        $out = [];
        foreach (array_chunk($points, 2) as [$x, $y]) {
            $out[] = $this->px($x);
            $out[] = $this->py($y);
        }

        return $out;
    }
}
