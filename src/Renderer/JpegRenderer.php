<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer;

/**
 * JPEG output. JPEG is lossy and has no transparency, so prefer PNG or SVG
 * unless a consumer insists on JPEG.
 */
final class JpegRenderer extends AbstractGdRenderer
{
    public function __construct(
        private readonly int $quality = 90,
    ) {}

    public function mimeType(): string
    {
        return 'image/jpeg';
    }

    public function extension(): string
    {
        return 'jpg';
    }

    protected function encode(\GdImage $image): bool
    {
        return imagejpeg($image, null, $this->quality);
    }
}
