<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Symbol\HeightModulatedSymbol;

/**
 * KIX (Klantenindex), the PostNL 4-state code for Dutch addresses.
 *
 * Uses the RM4SCC character set and bar patterns, without start/stop bars or a
 * check character, for up to 18 characters (postcode, house number, suffix).
 * Per "PostNL Handleiding KIX code".
 */
final class Kix extends RoyalMail4State
{
    public function name(): string
    {
        return 'KIX';
    }

    protected function maxLength(): int
    {
        return 18;
    }

    protected function doEncode(string $data): HeightModulatedSymbol
    {
        $states = '';
        foreach (str_split($data) as $character) {
            $states .= self::PATTERNS[(int) strpos(self::ALPHABET, $character)];
        }

        return $this->fromStates($states, $data);
    }
}
