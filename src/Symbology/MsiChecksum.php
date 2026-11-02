<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

/**
 * Check digit schemes for MSI Plessey.
 */
enum MsiChecksum: string
{
    case None = 'none';
    case Mod10 = 'mod10';
    case Mod1010 = 'mod1010';
    case Mod11 = 'mod11';
    case Mod1110 = 'mod1110';
}
