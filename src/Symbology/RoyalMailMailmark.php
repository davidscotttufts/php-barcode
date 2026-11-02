<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Encoding\GaloisField;
use PhpBarcode\Encoding\ReedSolomon;
use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\HeightModulatedSymbol;
use PhpBarcode\Symbology\Postal\LargeInteger;

/**
 * Royal Mail Mailmark 4-state barcode, types C (22 characters, 66 bars) and
 * L (26 characters, 78 bars).
 *
 * Input layout: format (0-4), version ID (1-4), class (0-9, A-E), supply chain
 * ID (2 digits for C, 6 for L), item ID (8 digits), then the 9-character
 * destination postcode + DPS (space padded, or "XY11" for international).
 * Shorter input is padded with spaces to 22 or 26 characters.
 *
 * Implements "Royal Mail Mailmark barcode C/L encoding and decoding
 * instructions" (Sept 2015); cross-checked with Zint (BSD-3-Clause).
 */
final class RoyalMailMailmark extends AbstractFourStateSymbology
{
    private const SET_A = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const SET_L = 'ABDEFGHJLNPQRSTUWXYZ';
    private const SET_N = '0123456789';

    /** Postcode + DPS patterns, types 1-6 (A = letter, L = limited letter, N = digit, S = space). */
    private const POSTCODE_FORMATS = [
        1 => 'ANANLLNLS', 2 => 'AANNLLNLS', 3 => 'AANNNLLNL',
        4 => 'AANANLLNL', 5 => 'ANNLLNLSS', 6 => 'ANNNLLNLS',
    ];

    private const INTERNATIONAL = 'XY11     ';

    /** Offsets that make each postcode type's value range disjoint. */
    private const POSTCODE_OFFSETS = [
        1 => 1,
        2 => 1 + 5408000000,
        3 => 1 + 2 * 5408000000,
        4 => 1 + 2 * 5408000000 + 54080000000,
        5 => 1 + 2 * 5408000000 + 54080000000 + 140608000000,
        6 => 1 + 2 * 5408000000 + 54080000000 + 140608000000 + 208000000,
    ];

    /** 6-bit bar patterns for data symbols in even (base-30) and odd (base-32) positions. */
    private const EVEN_SYMBOLS = [
        0x03, 0x05, 0x06, 0x09, 0x0A, 0x0C, 0x0F, 0x11, 0x12, 0x14, 0x17, 0x18, 0x1B, 0x1D, 0x1E,
        0x21, 0x22, 0x24, 0x27, 0x28, 0x2B, 0x2D, 0x2E, 0x30, 0x33, 0x35, 0x36, 0x39, 0x3A, 0x3C,
    ];
    private const ODD_SYMBOLS = [
        0x01, 0x02, 0x04, 0x07, 0x08, 0x0B, 0x0D, 0x0E, 0x10, 0x13, 0x15, 0x16, 0x19, 0x1A, 0x1C, 0x1F,
        0x20, 0x23, 0x25, 0x26, 0x29, 0x2A, 0x2C, 0x2F, 0x31, 0x32, 0x34, 0x37, 0x38, 0x3B, 0x3D, 0x3E,
    ];

    /** Interleaving of data and check symbols into extender groups, for types C and L. */
    private const EXTENDER_C = [3, 5, 7, 11, 13, 14, 16, 17, 19, 0, 1, 2, 4, 6, 8, 9, 10, 12, 15, 18, 20, 21];
    private const EXTENDER_L = [2, 5, 7, 8, 13, 14, 15, 16, 21, 22, 23, 0, 1, 3, 4, 6, 9, 10, 11, 12, 17, 18, 19, 20, 24, 25];

    public function name(): string
    {
        return 'Mailmark';
    }

    /**
     * Mailmark ascender and descender are each about 1.9 mm around a 1.3 mm tracker.
     */
    protected function trackerExtent(): array
    {
        return [0.37, 0.63];
    }

    protected function normalize(string $data): string
    {
        $data = strtoupper($data);
        $length = strlen($data);

        return match (true) {
            $length >= 14 && $length < 22 => str_pad($data, 22),
            $length > 22 && $length < 26 => str_pad($data, 26),
            default => $data,
        };
    }

    protected function validate(string $data): void
    {
        $length = strlen($data);
        if ($length !== 22 && $length !== 26) {
            throw InvalidDataException::invalidLength($this->name(), '14 to 26 characters', $length);
        }
        $this->assertAlphabet($data, self::SET_N . self::SET_A . ' ');

        if (!str_contains('01234', $data[0])) {
            throw new InvalidDataException('Mailmark format (1st character) must be 0-4.');
        }
        if (!str_contains('1234', $data[1])) {
            throw new InvalidDataException('Mailmark version ID (2nd character) must be 1-4.');
        }
        if (!str_contains('0123456789ABCDE', $data[2])) {
            throw new InvalidDataException('Mailmark class (3rd character) must be 0-9 or A-E.');
        }
        $ids = substr($data, 3, $length - 12);
        if (!ctype_digit($ids)) {
            throw new InvalidDataException('Mailmark supply chain ID and item ID must be digits.');
        }
        if (self::postcodeType(substr($data, -9)) === null) {
            throw new InvalidDataException(sprintf('Invalid Mailmark postcode + DPS "%s".', substr($data, -9)));
        }
    }

    protected function doEncode(string $data): HeightModulatedSymbol
    {
        $isTypeC = strlen($data) === 22;
        $postcode = substr($data, -9);
        $itemId = (int) substr($data, -17, 8);
        $supplyChainId = (int) substr($data, 3, $isTypeC ? 2 : 6);

        // Consolidated data value (CDV).
        $cdv = new LargeInteger(self::postcodeValue($postcode));
        $cdv->multiplyAdd(100000000, $itemId);
        $cdv->multiplyAdd($isTypeC ? 100 : 1000000, $supplyChainId);
        $cdv->multiplyAdd(15, (int) hexdec($data[2]));
        $cdv->multiplyAdd(5, (int) $data[0]);
        $cdv->multiplyAdd(4, (int) $data[1] - 1);

        // Data symbols: the trailing ones in base 32, the leading ones in base 30.
        [$dataCount, $base30Count, $checkCount] = $isTypeC ? [16, 9, 6] : [19, 11, 7];
        // Extracted least significant first, so each group is reversed afterwards.
        $base32 = [];
        for ($i = $base30Count; $i < $dataCount; $i++) {
            $base32[] = $cdv->divideBy(32);
        }
        $base30 = [];
        for ($i = 0; $i < $base30Count; $i++) {
            $base30[] = $cdv->divideBy(30);
        }
        $symbols = [...array_reverse($base30), ...array_reverse($base32)];

        $rs = new ReedSolomon(new GaloisField(32, 0x25, 1));
        $symbols = [...$symbols, ...$rs->encode($symbols, $checkCount)];

        // Map to 6-bit patterns, then interleave into extender groups.
        $extender = [];
        $order = $isTypeC ? self::EXTENDER_C : self::EXTENDER_L;
        foreach ($symbols as $i => $symbol) {
            $extender[$order[$i]] = $i < $base30Count ? self::EVEN_SYMBOLS[$symbol] : self::ODD_SYMBOLS[$symbol];
        }

        // Each 6-bit group becomes three bars: bit pairs (5,2), (4,1), (3,0) = (upper, lower) halves.
        $states = '';
        for ($group = 0, $count = count($extender); $group < $count; $group++) {
            $pattern = $extender[$group];
            for ($bar = 0; $bar < 3; $bar++) {
                $upper = ($pattern >> (5 - $bar)) & 1;
                $lower = ($pattern >> (2 - $bar)) & 1;
                // Odd extender groups are written upside down.
                if ($group % 2 === 1) {
                    [$upper, $lower] = [$lower, $upper];
                }
                $states .= match ([$upper, $lower]) {
                    [1, 1] => 'F',
                    [1, 0] => 'A',
                    [0, 1] => 'D',
                    default => 'T',
                };
            }
        }

        return $this->fromStates($states, rtrim($data));
    }

    /**
     * Identifies the postcode + DPS layout (1-6), 7 for international, or null if invalid.
     *
     * @return int<1, 7>|null
     */
    private static function postcodeType(string $postcode): ?int
    {
        if ($postcode === self::INTERNATIONAL) {
            return 7;
        }

        $type = match (true) {
            $postcode[7] === ' ' => 5,
            $postcode[8] === ' ' => ctype_digit($postcode[1]) ? (ctype_digit($postcode[2]) ? 6 : 1) : 2,
            default => ctype_digit($postcode[3]) ? 3 : 4,
        };

        foreach (str_split(self::POSTCODE_FORMATS[$type]) as $i => $class) {
            $set = match ($class) {
                'A' => self::SET_A,
                'L' => self::SET_L,
                'N' => self::SET_N,
                default => ' ',
            };
            if (!str_contains($set, $postcode[$i])) {
                return null;
            }
        }

        return $type;
    }

    private static function postcodeValue(string $postcode): int
    {
        $type = self::postcodeType($postcode);
        if ($type === null || $type === 7) {
            return 0;
        }

        $value = 0;
        foreach (str_split(self::POSTCODE_FORMATS[$type]) as $i => $class) {
            $value = match ($class) {
                'A' => $value * 26 + (int) strpos(self::SET_A, $postcode[$i]),
                'L' => $value * 20 + (int) strpos(self::SET_L, $postcode[$i]),
                'N' => $value * 10 + (int) $postcode[$i],
                default => $value,
            };
        }

        return $value + self::POSTCODE_OFFSETS[$type];
    }
}
