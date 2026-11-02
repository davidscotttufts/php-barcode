<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

/**
 * PLANET, the legacy USPS mail-tracking barcode (superseded by Intelligent Mail).
 *
 * Identical to POSTNET except that the bar heights are inverted (each digit
 * has three tall bars) and it carries 11 or 13 digits. Per USPS Publication 197.
 */
final class Planet extends Postnet
{
    public function name(): string
    {
        return 'PLANET';
    }

    protected function allowedLengths(): array
    {
        return [11, 13];
    }

    protected function tallBit(): string
    {
        return '0';
    }
}
