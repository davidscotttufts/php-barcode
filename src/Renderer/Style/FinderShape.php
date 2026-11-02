<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Style;

/**
 * How finder patterns ("eyes", e.g. QR's three corner squares) are drawn.
 */
enum FinderShape: string
{
    case Square = 'square';
    case Rounded = 'rounded';
    case Circle = 'circle';

    /**
     * Corner radius as a fraction of the shape's size.
     */
    public function cornerRadius(): float
    {
        return match ($this) {
            self::Square => 0.0,
            self::Rounded => 0.25,
            self::Circle => 0.5,
        };
    }
}
