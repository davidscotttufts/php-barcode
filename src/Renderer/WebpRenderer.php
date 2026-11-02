<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer;

/**
 * WebP output (requires GD built with WebP support).
 */
final class WebpRenderer extends AbstractGdRenderer
{
    public function __construct(
        private readonly int $quality = 90,
    ) {}

    public function mimeType(): string
    {
        return 'image/webp';
    }

    public function extension(): string
    {
        return 'webp';
    }

    protected function encode(\GdImage $image): bool
    {
        return imagewebp($image, null, $this->quality);
    }
}
