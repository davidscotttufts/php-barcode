<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Canvas;

use PhpBarcode\Renderer\Color;
use PhpBarcode\Renderer\Style\Logo;

/**
 * Encapsulated PostScript (EPSF-3.0), the traditional format for placing
 * barcodes into print layouts (InDesign, Illustrator, QuarkXPress).
 */
final class EpsCanvas extends AbstractVectorCanvas
{
    public function image(float $x, float $y, float $width, float $height, Logo $logo): void
    {
        $image = self::decodeLogo($logo);

        // PostScript Level 2 has no alpha; composite onto the background color.
        $rgb = $image['opaque'] ? $image['rgb'] : self::flatten($image['rgb'], $image['alpha'], $this->options->background);

        $this->overlay .= sprintf(
            "gsave %s %s translate %s %s scale\n%d %d 8 [%d 0 0 -%d 0 %d] currentfile /ASCIIHexDecode filter false 3 colorimage\n%s>\ngrestore\n",
            self::n($this->px($x)),
            self::n($this->py($y + $height)),
            self::n($this->px($width)),
            self::n($this->px($height)),
            $image['width'],
            $image['height'],
            $image['width'],
            $image['height'],
            $image['height'],
            chunk_split(bin2hex($rgb), 78, "\n"),
        );
    }

    public function text(string $text, float $x, float $baseline, float $size, string $anchor, int $rotation = 0): void
    {
        $text = self::winAnsi($text);
        $points = $this->px($size);
        $offset = $this->anchorOffset($text, $points, $anchor);

        $this->overlay .= sprintf("/ISOCourier findfont %s scalefont setfont\n", self::n($points));
        $this->overlay .= $rotation === 90
            ? sprintf("gsave %s %s translate -90 rotate %s 0 moveto %s show grestore\n", self::n($this->px($x)), self::n($this->py($baseline)), self::n(-$offset), self::literal($text))
            : sprintf("%s %s moveto %s show\n", self::n($this->px($x) - $offset), self::n($this->py($baseline)), self::literal($text));
    }

    public function finish(): string
    {
        $width = $this->pageWidth();
        $height = $this->pageHeight();

        $eps = "%!PS-Adobe-3.0 EPSF-3.0\n"
            . sprintf("%%%%BoundingBox: 0 0 %d %d\n", (int) ceil($width), (int) ceil($height))
            . sprintf("%%%%HiResBoundingBox: 0 0 %s %s\n", self::n($width), self::n($height))
            . sprintf("%%%%Title: %s\n", preg_replace('/[\r\n]/', ' ', self::winAnsi($this->title)))
            . "%%Creator: php-barcode\n%%LanguageLevel: 2\n%%Pages: 1\n%%EndComments\n"
            . "/ISOCourier /Courier findfont dup length dict begin {1 index /FID ne {def} {pop pop} ifelse} forall\n"
            . "/Encoding ISOLatin1Encoding def currentdict end definefont pop\n";

        if ($this->options->background->alpha > 0.0) {
            $eps .= sprintf("%s setrgbcolor 0 0 %s %s rectfill\n", self::rgb($this->options->background), self::n($width), self::n($height));
        }

        $eps .= sprintf("%s setrgbcolor\nnewpath\n%sfill\n", self::rgb($this->ink()), $this->path);

        if ($this->overlay !== '') {
            $eps .= sprintf("%s setrgbcolor\n%s", self::rgb($this->ink()), $this->overlay);
        }

        return $eps . "showpage\n%%EOF\n";
    }

    protected function moveTo(float $x, float $y): string
    {
        return sprintf("%s %s moveto\n", self::n($x), self::n($y));
    }

    protected function lineTo(float $x, float $y): string
    {
        return sprintf("%s %s lineto\n", self::n($x), self::n($y));
    }

    protected function curveTo(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): string
    {
        return sprintf("%s %s %s %s %s %s curveto\n", self::n($x1), self::n($y1), self::n($x2), self::n($y2), self::n($x3), self::n($y3));
    }

    protected function closePath(): string
    {
        return "closepath\n";
    }

    private static function flatten(string $rgb, string $alpha, Color $background): string
    {
        $out = '';
        $bg = [$background->red, $background->green, $background->blue];
        for ($i = 0, $n = strlen($alpha); $i < $n; $i++) {
            $a = ord($alpha[$i]) / 255;
            for ($c = 0; $c < 3; $c++) {
                $out .= chr(max(0, min(255, (int) round(ord($rgb[3 * $i + $c]) * $a + $bg[$c] * (1 - $a)))));
            }
        }

        return $out;
    }
}
