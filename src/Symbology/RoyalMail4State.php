<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\HeightModulatedSymbol;

/**
 * Royal Mail 4-State Customer Code (RM4SCC, "CBC"), used on UK mail for the
 * postcode and delivery point suffix.
 *
 * Encodes 0-9 and A-Z (lower case is upper-cased), each as four bars, with a
 * check character between an ascender start bar and a full stop bar.
 * Character table and checksum after the Royal Mail Customer Barcode
 * specification, cross-checked with Zint (BSD-3-Clause).
 */
class RoyalMail4State extends AbstractFourStateSymbology
{
    protected const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** Bar states for each character, indexed by position in ALPHABET. */
    protected const PATTERNS = [
        'TTFF', 'TDAF', 'TDFA', 'DTAF', 'DTFA', 'DDAA', 'TADF', 'TFTF', 'TFDA',
        'DATF', 'DADA', 'DFTA', 'TAFD', 'TFAD', 'TFFT', 'DAAD', 'DAFT', 'DFAT',
        'ATDF', 'ADTF', 'ADDA', 'FTTF', 'FTDA', 'FDTA', 'ATFD', 'ADAD', 'ADFT',
        'FTAD', 'FTFT', 'FDAT', 'AADD', 'AFTD', 'AFDT', 'FATD', 'FADT', 'FFTT',
    ];

    public function name(): string
    {
        return 'RM4SCC';
    }

    protected function maxLength(): int
    {
        return 50;
    }

    protected function normalize(string $data): string
    {
        return strtoupper(str_replace(' ', '', $data));
    }

    protected function validate(string $data): void
    {
        $this->assertAlphabet($data, self::ALPHABET);

        if (strlen($data) > $this->maxLength()) {
            throw InvalidDataException::invalidLength($this->name(), sprintf('at most %d characters', $this->maxLength()), strlen($data));
        }
    }

    protected function doEncode(string $data): HeightModulatedSymbol
    {
        $top = 0;
        $bottom = 0;
        $states = 'A';

        foreach (str_split($data) as $character) {
            $value = (int) strpos(self::ALPHABET, $character);
            $states .= self::PATTERNS[$value];
            // The checksum sums each character's row (top) and column (bottom) in the 6×6 table, 1-based.
            $top += intdiv($value, 6) + 1;
            $bottom += $value % 6 + 1;
        }

        $row = ($top + 5) % 6;
        $column = ($bottom + 5) % 6;
        $check = 6 * $row + $column;

        return $this->fromStates($states . self::PATTERNS[$check] . 'F', $data);
    }
}
