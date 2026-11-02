<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer;

enum Orientation: string
{
    case Horizontal = 'horizontal';

    /** Rotated 90° clockwise: the start of the barcode is at the top. */
    case Vertical = 'vertical';

    public static function fromName(string $name): self
    {
        return self::tryFrom(strtolower(trim($name)))
            ?? throw new \InvalidArgumentException(sprintf('Unknown orientation "%s"; use "horizontal" or "vertical".', $name));
    }
}
