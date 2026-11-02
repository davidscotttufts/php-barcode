<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

/**
 * Digit codes shared by EAN-13, EAN-8, UPC-A, UPC-E and the EAN-2/EAN-5 add-ons
 * (GS1 General Specifications, section 5.2).
 *
 * @internal
 */
final class EanTables
{
    /** Left-hand odd-parity ("L", set A) codes. */
    private const L_CODES = [
        '0001101', '0011001', '0010011', '0111101', '0100011',
        '0110001', '0101111', '0111011', '0110111', '0001011',
    ];

    public const GUARD = '101';
    public const CENTER_GUARD = '01010';

    public static function l(string $digit): string
    {
        return self::L_CODES[(int) $digit];
    }

    /** Right-hand ("R", set C) codes: the complement of L. */
    public static function r(string $digit): string
    {
        return strtr(self::l($digit), '01', '10');
    }

    /** Left-hand even-parity ("G", set B) codes: R reversed. */
    public static function g(string $digit): string
    {
        return strrev(self::r($digit));
    }

    /**
     * Encodes $digits using one parity letter (L or G) per digit.
     */
    public static function withParity(string $digits, string $parity): string
    {
        $bits = '';
        foreach (str_split($digits) as $i => $digit) {
            $bits .= $parity[$i] === 'L' ? self::l($digit) : self::g($digit);
        }

        return $bits;
    }
}
