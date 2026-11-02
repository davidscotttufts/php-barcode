<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

/**
 * Bearer bar styles for ITF-14 (GS1 General Specifications 5.3.4).
 */
enum BearerBars: string
{
    case None = 'none';

    /** Bars above and below the symbol only. */
    case Horizontal = 'horizontal';

    /** A full frame around the symbol and its quiet zones (the GS1 default for printing on corrugate). */
    case Box = 'box';
}
