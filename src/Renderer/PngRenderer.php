<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer;

/**
 * Lossless PNG output with transparency support: the best raster format for barcodes.
 *
 * @param int $compression zlib level from 0 (none) to 9 (smallest file)
 */
final class PngRenderer extends AbstractGdRenderer
{
    public function __construct(
        private readonly int $compression = 9,
    ) {}

    public function mimeType(): string
    {
        return 'image/png';
    }

    public function extension(): string
    {
        return 'png';
    }

    protected function encode(\GdImage $image): bool
    {
        return imagepng($image, null, $this->compression);
    }
}
