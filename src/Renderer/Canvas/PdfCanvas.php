<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Canvas;

use PhpBarcode\Renderer\Style\Logo;

/**
 * A single-page vector PDF sized exactly to the symbol, written without any
 * PDF library. Ideal for print workflows and for placing into other documents.
 */
final class PdfCanvas extends AbstractVectorCanvas
{
    /** @var list<array{image: string, mask: string|null}> Image XObjects and optional soft masks, named Im1, Im2, … */
    private array $images = [];

    public function image(float $x, float $y, float $width, float $height, Logo $logo): void
    {
        $decoded = self::decodeLogo($logo);
        $this->images[] = $this->imageObject($decoded);
        $name = 'Im' . count($this->images);

        $this->overlay .= sprintf(
            "q %s 0 0 %s %s %s cm /%s Do Q\n",
            self::n($this->px($width)),
            self::n($this->px($height)),
            self::n($this->px($x)),
            self::n($this->py($y + $height)),
            $name,
        );
    }

    public function text(string $text, float $x, float $baseline, float $size, string $anchor, int $rotation = 0): void
    {
        $text = self::winAnsi($text);
        $points = $this->px($size);
        $offset = $this->anchorOffset($text, $points, $anchor);

        $matrix = $rotation === 90
            ? sprintf('0 -1 1 0 %s %s', self::n($this->px($x)), self::n($this->py($baseline) + $offset))
            : sprintf('1 0 0 1 %s %s', self::n($this->px($x) - $offset), self::n($this->py($baseline)));

        $this->overlay .= sprintf("BT /F1 %s Tf %s Tm %s Tj ET\n", self::n($points), $matrix, self::literal($text));
    }

    public function finish(): string
    {
        $content = '';
        if ($this->options->background->alpha > 0.0) {
            $content .= sprintf("%s rg 0 0 %s %s re f\n", self::rgb($this->options->background), self::n($this->pageWidth()), self::n($this->pageHeight()));
        }
        $content .= sprintf("%s rg\n%sf\n", self::rgb($this->ink()), $this->path);
        $content .= sprintf("%s rg\n%s", self::rgb($this->ink()), $this->overlay);

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';

        $xObjects = '';
        $next = 6;
        $imageObjects = [];
        foreach ($this->images as $index => ['image' => $image, 'mask' => $mask]) {
            $imageNumber = $next++;
            if ($mask !== null) {
                // The soft mask (alpha channel) is the object right after its image.
                $imageObjects[$next] = $mask;
                $image = (string) preg_replace('~/SMask ~', sprintf('/SMask %d 0 R', $next++), $image, 1);
            }
            $imageObjects[$imageNumber] = $image;
            $xObjects .= sprintf(' /Im%d %d 0 R', $index + 1, $imageNumber);
        }

        $objects[3] = sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 4 0 R >>%s >> /Contents 5 0 R >>',
            self::n($this->pageWidth()),
            self::n($this->pageHeight()),
            $xObjects === '' ? '' : ' /XObject <<' . $xObjects . ' >>',
        );
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>';
        $objects[5] = self::stream($content);
        $objects += $imageObjects;
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $number, $body);
        }

        $count = count($objects) + 1;
        $xref = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", $count);
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info << /Title %s /Producer (php-barcode) >> >>\nstartxref\n%d\n%%%%EOF\n",
            $count,
            self::literal(self::winAnsi($this->title)),
            $xref,
        );
    }

    protected function moveTo(float $x, float $y): string
    {
        return sprintf("%s %s m\n", self::n($x), self::n($y));
    }

    protected function lineTo(float $x, float $y): string
    {
        return sprintf("%s %s l\n", self::n($x), self::n($y));
    }

    protected function curveTo(float $x1, float $y1, float $x2, float $y2, float $x3, float $y3): string
    {
        return sprintf("%s %s %s %s %s %s c\n", self::n($x1), self::n($y1), self::n($x2), self::n($y2), self::n($x3), self::n($y3));
    }

    protected function closePath(): string
    {
        return "h\n";
    }

    /**
     * @param array{width: int, height: int, rgb: string, alpha: string, opaque: bool} $image
     *
     * @return array{image: string, mask: string|null}
     */
    private function imageObject(array $image): array
    {
        $dictionary = sprintf('/Type /XObject /Subtype /Image /Width %d /Height %d /BitsPerComponent 8', $image['width'], $image['height']);

        return [
            // "/SMask " is completed with the mask's object number in finish().
            'image' => self::stream($image['rgb'], $dictionary . ' /ColorSpace /DeviceRGB' . ($image['opaque'] ? '' : ' /SMask ')),
            'mask' => $image['opaque'] ? null : self::stream($image['alpha'], $dictionary . ' /ColorSpace /DeviceGray'),
        ];
    }

    private static function stream(string $data, string $dictionary = ''): string
    {
        $filter = '';
        if (function_exists('gzcompress')) {
            $data = (string) gzcompress($data, 9);
            $filter = ' /Filter /FlateDecode';
        }

        return sprintf("<< %s%s /Length %d >>\nstream\n%s\nendstream", $dictionary, $filter, strlen($data), $data);
    }
}
