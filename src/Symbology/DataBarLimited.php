<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\LinearSymbol;

/**
 * GS1 DataBar Limited (formerly RSS Limited), a compact single-row symbol
 * for small items such as healthcare products.
 *
 * Encodes a GTIN-14 whose indicator digit is 0 or 1: pass 13 digits, 14 digits
 * with the check digit, or "(01)" followed by the GTIN.
 */
final class DataBarLimited extends AbstractDataBar
{
    private const G_SUM = [0, 183064, 820064, 1000776, 1491021, 1979845, 1996939];
    private const T_EVEN = [28, 728, 6454, 203, 2408, 1, 16632];
    private const MODULES_ODD = [17, 13, 9, 15, 11, 19, 7];
    private const WIDEST_ODD = [6, 5, 3, 5, 4, 8, 1];

    private const CHECKSUM_WEIGHTS = [
        [1, 3, 9, 27, 81, 65, 17, 51, 64, 14, 42, 37, 22, 66],
        [20, 60, 2, 6, 18, 54, 73, 41, 34, 13, 39, 28, 84, 74],
    ];

    /** ISO/IEC 24724 Annex C: the 89 check character finder patterns, as element widths. */
    private const FINDER_PATTERNS = [
        '11111111113311', '11111111123211', '11111111133111', '11111112113211', '11111112123111', '11111113113111',
        '11111211113211', '11111211123111', '11111212113111', '11111311113111', '11121111113211', '11121111123111',
        '11121112113111', '11121211113111', '11131111113111', '12111111113211', '12111111123111', '12111112113111',
        '12111211113111', '12121111113111', '13111111113111', '11111111212311', '11111111222211', '11111111232111',
        '11111112212211', '11111112222111', '11111113212111', '11111211212211', '11111211222111', '11111212212111',
        '11111311212111', '11121111212211', '11121111222111', '11121112212111', '11121211212111', '11131111212111',
        '12111111212211', '12111111222111', '12111112212111', '12111211212111', '12121111212111', '13111111212111',
        '11111111311311', '11111111321211', '11111112311211', '11121111311211', '12111111311211', '11111121112311',
        '11111121122211', '11111121132111', '11111122112211', '11121121112211', '11121121122111', '11121122112111',
        '11121221112111', '11131121112111', '12111121112211', '12111121122111', '12121121112111', '11112111112311',
        '11112111122211', '11112111132111', '11112112112211', '11112112122111', '11112211112211', '12112111112211',
        '12112111122111', '12112112112111', '12112211112111', '12122111112111', '13112111112111', '11211111112311',
        '11211111122211', '11211111132111', '11211112112211', '11211112122111', '11211113112111', '11211211112211',
        '11211211122111', '11221111112211', '21111111122211', '21111111132111', '21111112112211', '21111112122111',
        '21111113112111', '21111211122111', '21111212112111', '21121111122111', '21111111221211',
    ];

    public function name(): string
    {
        return 'GS1 DataBar Limited';
    }

    public function quietZone(): int
    {
        return 1;
    }

    protected function validate(string $data): void
    {
        $gtin = $this->gtinWithoutCheckDigit($data);
        if ($gtin[0] !== '0' && $gtin[0] !== '1') {
            throw new InvalidDataException(sprintf(
                '%s can only encode GTINs whose first digit is 0 or 1, got %s.',
                $this->name(),
                $gtin[0],
            ));
        }
    }

    protected function doEncode(string $data): LinearSymbol
    {
        $gtin = $this->gtinWithoutCheckDigit($data);
        $value = (int) $gtin;
        $pairs = [intdiv($value, 2013571), $value % 2013571];

        $widths = [];
        foreach ($pairs as $i => $pair) {
            $group = 0;
            for ($g = 6; $g > 0; $g--) {
                if ($pair >= self::G_SUM[$g]) {
                    $group = $g;
                    break;
                }
            }
            $pair -= self::G_SUM[$group];
            $widths[$i] = self::characterWidths(
                intdiv($pair, self::T_EVEN[$group]),
                $pair % self::T_EVEN[$group],
                self::MODULES_ODD[$group],
                26 - self::MODULES_ODD[$group],
                7,
                self::WIDEST_ODD[$group],
                false,
            );
        }

        $checksum = 0;
        for ($i = 0; $i < 14; $i++) {
            $checksum += self::CHECKSUM_WEIGHTS[0][$i] * $widths[0][$i] + self::CHECKSUM_WEIGHTS[1][$i] * $widths[1][$i];
        }
        $finder = array_map('intval', str_split(self::FINDER_PATTERNS[$checksum % 89]));

        // Left guard (bar, space), left character, check finder, right character, right guard (bar).
        $elements = [1, ...$widths[0], ...$finder, ...$widths[1], 1, 1];

        return new LinearSymbol($elements, $this->gtinText($gtin), $this->name(), $this->quietZone());
    }
}
