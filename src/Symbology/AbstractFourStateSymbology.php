<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Symbol\HeightModulatedSymbol;

/**
 * Base for height-modulated postal symbologies, which produce a
 * HeightModulatedSymbol from a string of DAFT bar states.
 */
abstract class AbstractFourStateSymbology extends AbstractSymbology
{
    public function quietZone(): int
    {
        return 6;
    }

    /**
     * Top and bottom of the tracker as fractions of the full bar height.
     *
     * @return array{float, float}
     */
    protected function trackerExtent(): array
    {
        return [0.375, 0.625];
    }

    /**
     * Wraps DAFT states ("FADT…") in a symbol carrying this symbology's metadata.
     */
    protected function fromStates(string $states, string $text): HeightModulatedSymbol
    {
        [$top, $bottom] = $this->trackerExtent();

        return HeightModulatedSymbol::fromStates($states, $text, $this->name(), $this->quietZone(), $top, $bottom);
    }
}
