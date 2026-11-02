<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\DataMatrix;

/**
 * Which Data Matrix symbol shapes the encoder may choose from.
 */
enum DataMatrixShape: string
{
    /** Square symbols only (10×10 … 144×144). The usual choice. */
    case Square = 'square';

    /** Rectangular symbols only (8×18 … 16×48, plus DMRE sizes when enabled). */
    case Rectangle = 'rectangle';

    /** Whichever symbol is smallest. */
    case Any = 'any';

    public function allows(SymbolSize $size): bool
    {
        return match ($this) {
            self::Square => $size->isSquare(),
            self::Rectangle => !$size->isSquare(),
            self::Any => true,
        };
    }
}
