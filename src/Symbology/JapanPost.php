<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\HeightModulatedSymbol;

/**
 * Japan Post customer barcode (Kasutama barcode) for postcode plus address.
 *
 * Accepts digits, "-" and A-Z. Letters are written with control characters
 * CC1-CC3 followed by a digit, and the 20 symbol characters are padded with
 * CC4. A modulo-19 check character follows. Per the Japan Post Zip/Barcode
 * Manual; tables cross-checked with Zint (BSD-3-Clause).
 */
final class JapanPost extends AbstractFourStateSymbology
{
    /** Internal character set: digits, hyphen, and control characters CC1 ("a") to CC8 ("h"). */
    private const SYMBOLS = '1234567890-abcdefgh';

    /** Check values use the same characters with 0-9 in numeric order. */
    private const CHECK_VALUES = '0123456789-abcdefgh';

    /** Bar states for each entry in SYMBOLS. */
    private const PATTERNS = [
        'FFT', 'FDA', 'DFA', 'FAD', 'FTF', 'DAF', 'AFD', 'ADF', 'TFF', 'FTT',
        'TFT', 'DAT', 'DTA', 'ADT', 'TDA', 'ATD', 'TAD', 'TTF', 'FFF',
    ];

    private const LENGTH = 20;

    public function name(): string
    {
        return 'Japan Post';
    }

    protected function normalize(string $data): string
    {
        return strtoupper($data);
    }

    protected function validate(string $data): void
    {
        $this->assertAlphabet($data, '0123456789-ABCDEFGHIJKLMNOPQRSTUVWXYZ');

        $length = strlen($this->toSymbols($data));
        if ($length > self::LENGTH) {
            throw InvalidDataException::invalidLength($this->name(), 'at most 20 symbol characters (letters count as two)', $length);
        }
    }

    /**
     * Japan Post bars use equal ascender, tracker and descender heights.
     */
    protected function trackerExtent(): array
    {
        return [1 / 3, 2 / 3];
    }

    protected function doEncode(string $data): HeightModulatedSymbol
    {
        $symbols = str_pad($this->toSymbols($data), self::LENGTH, 'd');

        $states = 'FD';
        $sum = 0;
        foreach (str_split($symbols) as $symbol) {
            $states .= self::PATTERNS[(int) strpos(self::SYMBOLS, $symbol)];
            $sum += (int) strpos(self::CHECK_VALUES, $symbol);
        }

        $check = self::CHECK_VALUES[(19 - $sum % 19) % 19];
        $states .= self::PATTERNS[(int) strpos(self::SYMBOLS, $check)] . 'DF';

        return $this->fromStates($states, $data);
    }

    /**
     * Rewrites letters as control character + digit: A-J → a0-a9, K-T → b0-b9, U-Z → c0-c5.
     */
    private function toSymbols(string $data): string
    {
        return (string) preg_replace_callback(
            '/[A-Z]/',
            static function (array $match): string {
                $offset = ord($match[0]) - ord('A');

                return 'abc'[intdiv($offset, 10)] . ($offset % 10);
            },
            $data,
        );
    }
}
