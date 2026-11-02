<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Canvas;

use PhpBarcode\Exception\RenderException;
use PhpBarcode\Renderer\Color;
use PhpBarcode\Renderer\RenderOptions;
use PhpBarcode\Renderer\Style\Logo;

/**
 * Raster drawing with GD.
 *
 * Plain square symbols are drawn directly with every edge snapped to whole
 * pixels, for crisp bars. Curved shapes and gradients are drawn into a 4×
 * supersampled coverage mask and composited, giving anti-aliased dots and
 * rounded corners.
 */
final class GdCanvas implements Canvas
{
    private const SUPERSAMPLE = 4;
    private const MAX_MASK_PIXELS = 40_000_000;

    /** @var list<array{string, list<float>}> Dark shapes, recorded until finish(). */
    private array $shapes = [];

    /** @var list<\Closure(\GdImage): void> Logos and text, drawn on top. */
    private array $overlays = [];

    private bool $needsMask;

    /**
     * @param array{x: float, y: float, width: float, height: float} $fillBox
     * @param \Closure(\GdImage): string $encoder
     */
    public function __construct(
        private readonly float $width,
        private readonly float $height,
        private readonly RenderOptions $options,
        private readonly array $fillBox,
        private readonly \Closure $encoder,
    ) {
        $this->needsMask = $options->gradient !== null;
    }

    public function rect(float $x, float $y, float $width, float $height, float $radius = 0.0): void
    {
        $this->shapes[] = ['rect', [$x, $y, $width, $height, $radius]];
        $this->needsMask = $this->needsMask || $radius > 0;
    }

    public function circle(float $cx, float $cy, float $radius): void
    {
        $this->shapes[] = ['circle', [$cx, $cy, $radius]];
        $this->needsMask = true;
    }

    public function ring(float $x, float $y, float $size, float $thickness, float $radius = 0.0): void
    {
        if ($radius <= 0) {
            // A square ring is just four rectangles.
            $this->rect($x, $y, $size, $thickness);
            $this->rect($x, $y + $size - $thickness, $size, $thickness);
            $this->rect($x, $y + $thickness, $thickness, $size - 2 * $thickness);
            $this->rect($x + $size - $thickness, $y + $thickness, $thickness, $size - 2 * $thickness);

            return;
        }

        $this->shapes[] = ['ring', [$x, $y, $size, $thickness, $radius]];
        $this->needsMask = true;
    }

    public function polygon(array $points): void
    {
        $this->shapes[] = ['polygon', array_merge(...array_map(static fn(array $p): array => [$p[0], $p[1]], $points))];
        $this->needsMask = true;
    }

    public function annulus(float $cx, float $cy, float $outer, float $inner): void
    {
        $this->shapes[] = ['annulus', [$cx, $cy, $outer, $inner]];
        $this->needsMask = true;
    }

    public function image(float $x, float $y, float $width, float $height, Logo $logo): void
    {
        if ($logo->mimeType === 'image/svg+xml') {
            throw new RenderException('SVG logos can only be used with SVG output; supply a PNG, JPEG, GIF or WebP logo for raster output.');
        }

        $this->overlays[] = static function (\GdImage $canvas) use ($x, $y, $width, $height, $logo): void {
            $source = @imagecreatefromstring($logo->data)
                ?: throw new RenderException('GD could not decode the logo image.');
            imagealphablending($canvas, true);
            imagecopyresampled(
                $canvas,
                $source,
                (int) round($x),
                (int) round($y),
                0,
                0,
                (int) round($width),
                (int) round($height),
                imagesx($source),
                imagesy($source),
            );
        };
    }

    public function text(string $text, float $x, float $baseline, float $size, string $anchor, int $rotation = 0): void
    {
        $this->overlays[] = fn(\GdImage $canvas) => $this->drawText($canvas, $text, $x, $baseline, $size, $anchor, $rotation);
    }

    public function finish(): string
    {
        $width = max(1, (int) ceil($this->width));
        $height = max(1, (int) ceil($this->height));

        $image = imagecreatetruecolor($width, $height)
            ?: throw new RenderException('GD could not allocate the image.');
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, self::allocate($image, $this->options->background));

        if ($this->needsMask) {
            $this->composite($image, $width, $height);
        } else {
            $this->drawDirect($image);
        }

        imagealphablending($image, true);
        foreach ($this->overlays as $overlay) {
            $overlay($image);
        }

        if ($this->options->dpi !== null) {
            imageresolution($image, $this->options->dpi, $this->options->dpi);
        }

        return ($this->encoder)($image);
    }

    private function drawDirect(\GdImage $image): void
    {
        $ink = self::allocate($image, $this->options->foreground);
        imagealphablending($image, $this->options->foreground->isOpaque() ? false : true);

        foreach ($this->shapes as [, [$x, $y, $w, $h]]) {
            // Round every edge independently so fractional sizes never leave gaps or overlaps.
            $left = (int) round($x);
            $top = (int) round($y);
            $right = (int) round($x + $w) - 1;
            $bottom = (int) round($y + $h) - 1;
            imagefilledrectangle($image, $left, $top, max($left, $right), max($top, $bottom), $ink);
        }
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function composite(\GdImage $image, int $width, int $height): void
    {
        $scale = $width * $height * self::SUPERSAMPLE ** 2 <= self::MAX_MASK_PIXELS ? self::SUPERSAMPLE : 1;
        $mask = imagecreatetruecolor($width * $scale, $height * $scale)
            ?: throw new RenderException('GD could not allocate the drawing mask.');
        $on = (int) imagecolorallocate($mask, 255, 255, 255);
        $off = (int) imagecolorallocate($mask, 0, 0, 0);

        // Rings cut their holes by painting "off", so draw them largest first:
        // a later, smaller ring inside the hole then stays visible.
        $shapes = $this->shapes;
        usort($shapes, static fn(array $a, array $b): int => ($b[0] === 'annulus' ? $b[1][2] : -1.0) <=> ($a[0] === 'annulus' ? $a[1][2] : -1.0));

        foreach ($shapes as [$type, $p]) {
            $p = array_map(static fn(float $v): float => $v * $scale, $p);
            match ($type) {
                'rect' => self::maskRoundedRect($mask, $p[0], $p[1], $p[2], $p[3], $p[4], $on),
                'circle' => imagefilledellipse($mask, (int) round($p[0]), (int) round($p[1]), (int) round(2 * $p[2]), (int) round(2 * $p[2]), $on),
                'ring' => self::maskRing($mask, $p, $on, $off),
                'polygon' => imagefilledpolygon($mask, array_map(static fn(float $v): int => (int) round($v), $p), $on),
                'annulus' => self::maskAnnulus($mask, $p, $on, $off),
                default => null,
            };
        }

        if ($scale > 1) {
            $coverage = imagecreatetruecolor($width, $height)
                ?: throw new RenderException('GD could not allocate the coverage image.');
            imagecopyresampled($coverage, $mask, 0, 0, 0, 0, $width, $height, $width * $scale, $height * $scale);
            $mask = $coverage;
        }

        $background = $this->options->background;
        $gradient = $this->options->gradient;
        $box = $this->fillBox;
        $cache = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $c = (imagecolorat($mask, $x, $y) >> 16) & 0xFF;
                if ($c === 0) {
                    continue;
                }
                $ink = $gradient === null
                    ? $this->options->foreground
                    : $gradient->at($gradient->positionIn($x + 0.5, $y + 0.5, $box['x'], $box['y'], $box['width'], $box['height']));
                $key = $ink->toHex() . $ink->alpha . ':' . $c;
                $cache[$key] ??= self::blend($image, $background, $ink, $c / 255);
                imagesetpixel($image, $x, $y, $cache[$key]);
            }
        }
    }

    /**
     * Mixes ink over background at the given coverage (0..1).
     */
    private static function blend(\GdImage $image, Color $background, Color $ink, float $coverage): int
    {
        $inkAlpha = $ink->alpha * $coverage;
        $outAlpha = $inkAlpha + $background->alpha * (1 - $inkAlpha);
        if ($outAlpha <= 0.0) {
            return self::allocate($image, new Color(0, 0, 0, 0.0));
        }

        $mix = static fn(int $fg, int $bg): int => max(0, min(255, (int) round(
            ($fg * $inkAlpha + $bg * $background->alpha * (1 - $inkAlpha)) / $outAlpha,
        )));

        return self::allocate($image, new Color(
            $mix($ink->red, $background->red),
            $mix($ink->green, $background->green),
            $mix($ink->blue, $background->blue),
            min(1.0, $outAlpha),
        ));
    }

    private static function maskRoundedRect(\GdImage $mask, float $x, float $y, float $w, float $h, float $r, int $color): void
    {
        $r = max(0.0, min($r, $w / 2, $h / 2));
        [$x1, $y1, $x2, $y2] = [(int) round($x), (int) round($y), (int) round($x + $w) - 1, (int) round($y + $h) - 1];

        if ($r < 1) {
            imagefilledrectangle($mask, $x1, $y1, $x2, $y2, $color);

            return;
        }

        $ri = (int) round($r);
        $d = 2 * $ri;
        imagefilledrectangle($mask, $x1 + $ri, $y1, $x2 - $ri, $y2, $color);
        imagefilledrectangle($mask, $x1, $y1 + $ri, $x2, $y2 - $ri, $color);
        imagefilledellipse($mask, $x1 + $ri, $y1 + $ri, $d, $d, $color);
        imagefilledellipse($mask, $x2 - $ri, $y1 + $ri, $d, $d, $color);
        imagefilledellipse($mask, $x1 + $ri, $y2 - $ri, $d, $d, $color);
        imagefilledellipse($mask, $x2 - $ri, $y2 - $ri, $d, $d, $color);
    }

    /**
     * @param list<float> $p [x, y, size, thickness, radius]
     */
    private static function maskRing(\GdImage $mask, array $p, int $on, int $off): void
    {
        [$x, $y, $size, $thickness, $radius] = $p;
        $inner = $size - 2 * $thickness;
        self::maskRoundedRect($mask, $x, $y, $size, $size, $radius, $on);
        self::maskRoundedRect($mask, $x + $thickness, $y + $thickness, $inner, $inner, $size > 0 ? $radius * $inner / $size : 0.0, $off);
    }

    /**
     * @param list<float> $p [cx, cy, outer, inner]
     */
    private static function maskAnnulus(\GdImage $mask, array $p, int $on, int $off): void
    {
        [$cx, $cy, $outer, $inner] = $p;
        imagefilledellipse($mask, (int) round($cx), (int) round($cy), (int) round(2 * $outer), (int) round(2 * $outer), $on);
        if ($inner > 0) {
            imagefilledellipse($mask, (int) round($cx), (int) round($cy), (int) round(2 * $inner), (int) round(2 * $inner), $off);
        }
    }

    private function drawText(\GdImage $image, string $text, float $x, float $baseline, float $size, string $anchor, int $rotation): void
    {
        $color = $this->options->gradient->from ?? $this->options->foreground;
        $ink = self::allocate($image, $color);
        $fontFile = $this->options->fontFile;

        if ($fontFile !== null && function_exists('imagettftext')) {
            // GD measures TrueType sizes in points at 96 DPI.
            $points = $size * 0.75;
            $box = imagettfbbox($points, 0, $fontFile, $text)
                ?: throw new RenderException(sprintf('Could not read font "%s".', $fontFile));
            $advance = is_int($box[2] ?? null) && is_int($box[0] ?? null) ? $box[2] - $box[0] : 0;
            $offset = match ($anchor) {
                'middle' => $advance / 2,
                'end' => $advance,
                default => 0.0,
            };

            if ($rotation === 90) {
                imagettftext($image, $points, -90, (int) round($x), (int) round($baseline - $offset), $ink, $fontFile, $text);
            } else {
                imagettftext($image, $points, 0, (int) round($x - $offset), (int) round($baseline), $ink, $fontFile, $text);
            }

            return;
        }

        $font = self::builtinFontFor($size);
        $text = self::toAscii($text);
        $advance = imagefontwidth($font) * strlen($text);
        $fontHeight = imagefontheight($font);
        $offset = match ($anchor) {
            'middle' => $advance / 2,
            'end' => $advance,
            default => 0.0,
        };
        // Center GD's fixed-size bitmap font within the requested text height.
        $top = $baseline - $size + ($size - $fontHeight) / 2;

        if ($rotation === 0) {
            imagestring($image, $font, (int) round($x - $offset), (int) round($top), $text, $ink);

            return;
        }

        // Draw horizontally on a scratch image, then rotate it into place.
        $scratch = imagecreatetruecolor(max(1, $advance), max(1, $fontHeight))
            ?: throw new RenderException('GD could not allocate a text image.');
        imagealphablending($scratch, false);
        imagesavealpha($scratch, true);
        imagefill($scratch, 0, 0, self::allocate($scratch, new Color(0, 0, 0, 0.0)));
        imagestring($scratch, $font, 0, 0, $text, self::allocate($scratch, $color));
        $rotated = imagerotate($scratch, 270, self::allocate($scratch, new Color(0, 0, 0, 0.0)))
            ?: throw new RenderException('GD could not rotate text.');
        // Rotated clockwise, glyph tops face right: the font box starts just right of the baseline.
        $left = $x + ($size - $fontHeight) / 2;
        imagecopy($image, $rotated, (int) round($left), (int) round($baseline - $offset), 0, 0, imagesx($rotated), imagesy($rotated));
    }

    /**
     * The largest built-in GD font (1-5) whose height fits the requested size.
     *
     * @return int<1, 5>
     */
    private static function builtinFontFor(float $size): int
    {
        for ($font = 5; $font > 1; $font--) {
            if (imagefontheight($font) <= $size) {
                return $font;
            }
        }

        return 1;
    }

    /**
     * GD's built-in bitmap fonts only cover ASCII reliably, so transliterate
     * ("Grüße" → "Grusse", "·" → "."). Characters with no ASCII form become "?".
     * For real glyphs, use SVG output or set a TrueType font.
     */
    private static function toAscii(string $text): string
    {
        if (preg_match('//u', $text) !== 1) {
            return (string) preg_replace('/[^\x20-\x7E]/', '?', $text);
        }

        $transliterator = class_exists(\Transliterator::class) ? \Transliterator::create('Any-Latin; Latin-ASCII') : null;

        return (string) preg_replace_callback('/[^\x20-\x7E]/u', static function (array $match) use ($transliterator): string {
            $ascii = $transliterator?->transliterate($match[0]);
            if (!is_string($ascii) || $ascii === '' || preg_match('/^[\x20-\x7E]+$/', $ascii) !== 1) {
                // iconv decorates some letters with accent marks (é → 'e on macOS); drop them.
                $converted = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $match[0]) : false;
                $ascii = $converted === false ? '' : trim($converted, "'\"^`~");
            }

            return $ascii !== '' && preg_match('/^[\x20-\x7E]+$/', $ascii) === 1 ? $ascii : '?';
        }, $text);
    }

    private static function allocate(\GdImage $image, Color $color): int
    {
        $index = imagecolorallocatealpha($image, $color->red, $color->green, $color->blue, $color->gdAlpha());

        return $index === false ? throw new RenderException('GD could not allocate a color.') : $index;
    }
}
