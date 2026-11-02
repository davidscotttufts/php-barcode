<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\HeightModulatedSymbol;

/**
 * POSTNET, the legacy USPS ZIP code barcode (superseded by Intelligent Mail).
 *
 * Encodes a 5-digit ZIP, ZIP+4 (9 digits) or delivery point (11 digits),
 * followed by a modulo-10 check digit, between full-height frame bars. Each
 * digit is five bars, two of them tall. Per USPS Publication 25 / DMM 708.4.
 */
class Postnet extends AbstractFourStateSymbology
{
    /** Tall (1) and short (0) bars for digits 0-9, weights 7-4-2-1-0. */
    protected const DIGITS = [
        '11000', '00011', '00101', '00110', '01001',
        '01010', '01100', '10001', '10010', '10100',
    ];

    public function name(): string
    {
        return 'POSTNET';
    }

    /**
     * @return list<int>
     */
    protected function allowedLengths(): array
    {
        return [5, 9, 11];
    }

    /**
     * Whether a "1" in DIGITS denotes a tall bar (POSTNET) or a short one (PLANET).
     */
    protected function tallBit(): string
    {
        return '1';
    }

    protected function normalize(string $data): string
    {
        return str_replace([' ', '-'], '', $data);
    }

    protected function validate(string $data): void
    {
        $this->assertAlphabet($data, '0123456789');

        if (!in_array(strlen($data), $this->allowedLengths(), true)) {
            throw InvalidDataException::invalidLength(
                $this->name(),
                implode(', ', $this->allowedLengths()) . ' digits',
                strlen($data),
            );
        }
    }

    /**
     * Short bars are bottom-aligned at 40% of the full height.
     */
    protected function trackerExtent(): array
    {
        return [0.6, 1.0];
    }

    protected function doEncode(string $data): HeightModulatedSymbol
    {
        $check = (10 - array_sum(str_split($data)) % 10) % 10;

        $bits = '';
        foreach (str_split($data . $check) as $digit) {
            $bits .= self::DIGITS[(int) $digit];
        }

        $tall = $this->tallBit();
        $states = 'F' . strtr($bits, [$tall => 'F', ($tall === '1' ? '0' : '1') => 'T']) . 'F';

        return $this->fromStates($states, $data);
    }
}
