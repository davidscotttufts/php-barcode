<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Style;

/**
 * How individual modules of a 2D symbol are drawn. Linear symbols ignore this.
 */
enum ModuleShape: string
{
    case Square = 'square';
    case Rounded = 'rounded';
    case Dot = 'dot';

    /**
     * Corner radius as a fraction of the module size (0 = square, 0.5 = circle).
     */
    public function cornerRadius(): float
    {
        return match ($this) {
            self::Square => 0.0,
            self::Rounded => 0.3,
            self::Dot => 0.5,
        };
    }
}
