<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\HeightModulatedSymbol;
use PhpBarcode\Symbology\Postal\LargeInteger;

/**
 * USPS Intelligent Mail barcode (IMb, "OneCode"), 65 bars.
 *
 * Input is the 20-digit tracking code (barcode ID, service type, mailer ID,
 * serial number) optionally followed by a 5, 9 or 11-digit routing (ZIP) code.
 * The two parts may be separated by "-" or a space, or simply concatenated
 * (20, 25, 29 or 31 digits).
 *
 * Implements USPS-B-3200 (Rev. H): binary conversion, CRC-11 frame check,
 * codewords, and the 5-of-13 / 2-of-13 character tables, which are generated
 * with the algorithm in Appendix C rather than stored. Verified against the
 * USPS encoder test cases.
 */
final class UspsIntelligentMail extends AbstractFourStateSymbology
{
    /**
     * USPS-B-3200 Appendix D Table IV: for character i (0-9) and bit j (0-12),
     * the bar position (1-130) at index 13i + j. Positions 1-65 are descenders
     * of bars 1-65; positions 66-130 are their ascenders.
     */
    private const BAR_MAP = [
        67, 6, 78, 16, 86, 95, 34, 40, 45, 113, 117, 121, 62,
        87, 18, 104, 41, 76, 57, 119, 115, 72, 97, 2, 127, 26,
        105, 35, 122, 52, 114, 7, 24, 82, 68, 63, 94, 44, 77,
        112, 70, 100, 39, 30, 107, 15, 125, 85, 10, 65, 54, 88,
        20, 106, 46, 66, 8, 116, 29, 61, 99, 80, 90, 37, 123,
        51, 25, 84, 129, 56, 4, 109, 96, 28, 36, 47, 11, 71,
        33, 102, 21, 9, 17, 49, 124, 79, 64, 91, 42, 69, 53,
        60, 14, 1, 27, 103, 126, 75, 89, 50, 120, 19, 32, 110,
        92, 111, 130, 59, 31, 12, 81, 43, 55, 5, 74, 22, 101,
        128, 58, 118, 48, 108, 38, 98, 93, 23, 83, 13, 73, 3,
    ];

    /** CRC-11 generator polynomial and initial value from USPS-B-3200 Appendix C. */
    private const CRC_POLYNOMIAL = 0x0F35;
    private const CRC_INITIAL = 0x07FF;

    /** @var list<int>|null 5-of-13 table (1287 entries) followed by 2-of-13 table (78 entries). */
    private static ?array $characterTable = null;

    public function name(): string
    {
        return 'Intelligent Mail';
    }

    /**
     * IMb ascender, tracker and descender are each about a third of the height.
     */
    protected function trackerExtent(): array
    {
        return [1 / 3, 2 / 3];
    }

    protected function normalize(string $data): string
    {
        $data = trim($data);

        return preg_match('/^(\d{20})[- ](\d*)$/', $data, $parts) === 1 ? $parts[1] . $parts[2] : $data;
    }

    protected function validate(string $data): void
    {
        $this->assertAlphabet($data, '0123456789');

        if (!in_array(strlen($data), [20, 25, 29, 31], true)) {
            throw InvalidDataException::invalidLength(
                $this->name(),
                'a 20-digit tracking code plus an optional 5, 9 or 11-digit routing code',
                strlen($data),
            );
        }
        if ($data[1] > '4') {
            throw new InvalidDataException('Intelligent Mail barcode identifier: the second digit must be 0-4.');
        }
    }

    protected function doEncode(string $data): HeightModulatedSymbol
    {
        $tracking = substr($data, 0, 20);
        $routing = (string) substr($data, 20);

        // Step 1: convert the fields into a single 102-bit binary value.
        $value = LargeInteger::fromDecimal($routing === '' ? '0' : $routing);
        $value->add(match (strlen($routing)) {
            0 => 0,
            5 => 1,
            9 => 100001,
            default => 1000100001,
        });
        $value->multiplyAdd(10, (int) $tracking[0]);
        $value->multiplyAdd(5, (int) $tracking[1]);
        foreach (str_split(substr($tracking, 2)) as $digit) {
            $value->multiplyAdd(10, (int) $digit);
        }

        // Step 2: 11-bit frame check sequence over the 102 bits.
        $crc = self::crc11($value->toBits(102));

        // Step 3: codewords A (base 659·2) … J (base 636).
        $codewords = array_fill(0, 10, 0);
        $codewords[9] = $value->divideBy(636);
        for ($i = 8; $i >= 1; $i--) {
            $codewords[$i] = $value->divideBy(1365);
        }
        $codewords[0] = $value->toInt();

        // Step 4: fold orientation and the CRC's top bit into the codewords.
        $codewords[9] *= 2;
        if ($crc >= 1024) {
            $codewords[0] += 659;
        }

        // Step 5: codewords to 13-bit characters, inverted where the CRC bit is set.
        $table = self::characterTable();
        $characters = [];
        foreach ($codewords as $i => $codeword) {
            $character = $table[$codeword];
            $characters[] = ($crc >> $i) & 1 ? 0x1FFF - $character : $character;
        }

        // Step 6: distribute the 130 character bits over the 65 bars.
        $bits = array_fill(0, 130, 0);
        foreach ($characters as $i => $character) {
            for ($j = 0; $j < 13; $j++) {
                $bits[self::BAR_MAP[13 * $i + $j] - 1] = ($character >> $j) & 1;
            }
        }

        $states = '';
        for ($bar = 0; $bar < 65; $bar++) {
            $states .= match ([$bits[$bar + 65], $bits[$bar]]) {
                [1, 1] => 'F',
                [1, 0] => 'A',
                [0, 1] => 'D',
                default => 'T',
            };
        }

        return $this->fromStates($states, $routing === '' ? $tracking : $tracking . '-' . $routing);
    }

    /**
     * CRC-11 over a bit string, most significant bit first (USPS-B-3200 Appendix C).
     */
    private static function crc11(string $bits): int
    {
        $crc = self::CRC_INITIAL;
        foreach (str_split($bits) as $bit) {
            $crc = (($crc >> 10) ^ (int) $bit) & 1
                ? (($crc << 1) ^ self::CRC_POLYNOMIAL) & 0x7FF
                : ($crc << 1) & 0x7FF;
        }

        return $crc;
    }

    /**
     * The 5-of-13 and 2-of-13 character tables, built as in USPS-B-3200 Appendix C:
     * each 13-bit value with N set bits is paired with its bit-reversal; palindromes fill from the end.
     *
     * @return list<int>
     */
    private static function characterTable(): array
    {
        return self::$characterTable ??= [...self::nOf13(5, 1287), ...self::nOf13(2, 78)];
    }

    /**
     * @return list<int>
     */
    private static function nOf13(int $setBits, int $length): array
    {
        $pairs = [];
        $palindromes = [];

        for ($count = 0; $count < 8192; $count++) {
            if (substr_count(decbin($count), '1') !== $setBits) {
                continue;
            }
            $reverse = bindec(strrev(str_pad(decbin($count), 13, '0', STR_PAD_LEFT)));
            if ($reverse < $count) {
                continue;
            }
            if ($reverse === $count) {
                // Palindromes fill the table from the end.
                array_unshift($palindromes, $count);
            } else {
                $pairs[] = $count;
                $pairs[] = (int) $reverse;
            }
        }

        $table = [...$pairs, ...$palindromes];
        if (count($table) !== $length) {
            throw new \LogicException(sprintf('Expected %d table entries, generated %d.', $length, count($table)));
        }

        return $table;
    }
}
