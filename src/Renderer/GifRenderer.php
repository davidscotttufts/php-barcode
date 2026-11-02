<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer;

/**
 * GIF output, for legacy consumers.
 */
final class GifRenderer extends AbstractGdRenderer
{
    public function mimeType(): string
    {
        return 'image/gif';
    }

    public function extension(): string
    {
        return 'gif';
    }

    protected function encode(\GdImage $image): bool
    {
        return imagegif($image);
    }
}
