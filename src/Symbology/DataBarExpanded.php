<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Gs1\ElementString;
use PhpBarcode\Gs1\Gs1Exception;
use PhpBarcode\Symbol\LinearSymbol;
use PhpBarcode\Symbol\StackedLinearSymbol;
use PhpBarcode\Symbol\Symbol;

/**
 * GS1 DataBar Expanded and Expanded Stacked (formerly RSS Expanded): variable-length
 * GS1 data such as GTIN plus weight, price, batch or expiry date, used on fresh
 * food and coupons. Holds up to 74 numeric or 41 alphanumeric characters.
 *
 * Input is a GS1 element string, e.g. "(01)98898765432106(3202)012345(15)991231".
 * Pass $segmentsPerRow (an even number from 2 to 22) for the stacked variant.
 *
 * The compressed encodation methods for (01)+(310x/320x)+date, (01)+(392x/393x)
 * and the general-purpose field follow ISO/IEC 24724 §7.2.5 as implemented in
 * Zint (backend/rss.c and general_field.c, BSD-3-Clause, (c) Robin Stuart).
 */
final class DataBarExpanded extends AbstractDataBar
{
    private const G_SUM = [0, 348, 1388, 2948, 3988];
    private const T_EVEN = [4, 20, 52, 104, 204];
    private const MODULES_ODD = [12, 10, 8, 6, 4];
    private const WIDEST_ODD = [7, 5, 4, 3, 1];

    private const CHECKSUM_WEIGHTS = [
        [1, 3, 9, 27, 81, 32, 96, 77], [20, 60, 180, 118, 143, 7, 21, 63],
        [189, 145, 13, 39, 117, 140, 209, 205], [193, 157, 49, 147, 19, 57, 171, 91],
        [62, 186, 136, 197, 169, 85, 44, 132], [185, 133, 188, 142, 4, 12, 36, 108],
        [113, 128, 173, 97, 80, 29, 87, 50], [150, 28, 84, 41, 123, 158, 52, 156],
        [46, 138, 203, 187, 139, 206, 196, 166], [76, 17, 51, 153, 37, 111, 122, 155],
        [43, 129, 176, 106, 107, 110, 119, 146], [16, 48, 144, 10, 30, 90, 59, 177],
        [109, 116, 137, 200, 178, 112, 125, 164], [70, 210, 208, 202, 184, 130, 179, 115],
        [134, 191, 151, 31, 93, 68, 204, 190], [148, 22, 66, 198, 172, 94, 71, 2],
        [6, 18, 54, 162, 64, 192, 154, 40], [120, 149, 25, 75, 14, 42, 126, 167],
        [79, 26, 78, 23, 69, 207, 199, 175], [103, 98, 83, 38, 114, 131, 182, 124],
        [161, 61, 183, 127, 170, 88, 53, 159], [55, 165, 73, 8, 24, 72, 5, 15],
        [45, 135, 194, 160, 58, 174, 100, 89],
    ];

    private const FINDER_PATTERNS = [
        [1, 8, 4, 1, 1], [1, 1, 4, 8, 1], [3, 6, 4, 1, 1], [1, 1, 4, 6, 3], [3, 4, 6, 1, 1], [1, 1, 6, 4, 3],
        [3, 2, 8, 1, 1], [1, 1, 8, 2, 3], [2, 6, 5, 1, 1], [1, 1, 5, 6, 2], [2, 2, 9, 1, 1], [1, 1, 9, 2, 2],
    ];

    private const FINDER_SEQUENCES = [
        [1, 2], [1, 4, 3], [1, 6, 3, 8], [1, 10, 3, 8, 5], [1, 10, 3, 8, 7, 12], [1, 10, 3, 8, 9, 12, 11],
        [1, 2, 3, 4, 5, 6, 7, 8], [1, 2, 3, 4, 5, 6, 7, 10, 9], [1, 2, 3, 4, 5, 6, 7, 10, 11, 12],
        [1, 2, 3, 4, 5, 8, 7, 10, 9, 12, 11],
    ];

    private const WEIGHT_ROWS = [
        [0, 1, 2],
        [0, 5, 6, 3, 4],
        [0, 9, 10, 3, 4, 13, 14],
        [0, 17, 18, 3, 4, 13, 14, 7, 8],
        [0, 17, 18, 3, 4, 13, 14, 11, 12, 21, 22],
        [0, 17, 18, 3, 4, 13, 14, 15, 16, 21, 22, 19, 20],
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14],
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 17, 18, 15, 16],
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 17, 18, 19, 20, 21, 22],
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 13, 14, 11, 12, 17, 18, 15, 16, 21, 22, 19, 20],
    ];

    private const NUMERIC = 'numeric';
    private const ALPHANUMERIC = 'alphanumeric';
    private const ISO646 = 'iso646';

    private const ALPHANUMERIC_PUNCTUATION = '*,-./';
    private const ISO646_PUNCTUATION = "!\"%&'()*+,-./:;<=>?_ ";

    /** Columns (segment pairs) per row, or null for the single-row symbol. */
    private readonly ?int $columns;

    /**
     * @param int|null $segmentsPerRow Null for single-row Expanded; an even number 2-22 for Expanded Stacked.
     */
    public function __construct(
        ?int $segmentsPerRow = null,
        private readonly bool $checkAssociations = true,
    ) {
        if ($segmentsPerRow !== null && ($segmentsPerRow < 2 || $segmentsPerRow > 22 || $segmentsPerRow % 2 !== 0)) {
            throw new \InvalidArgumentException('DataBar Expanded Stacked needs an even number of segments per row, from 2 to 22.');
        }
        $this->columns = $segmentsPerRow === null ? null : intdiv($segmentsPerRow, 2);
    }

    public function name(): string
    {
        return $this->columns === null ? 'GS1 DataBar Expanded' : 'GS1 DataBar Expanded Stacked';
    }

    protected function validate(string $data): void
    {
        $this->binary($this->elementString($data)->toEncodable());
    }

    protected function doEncode(string $data): Symbol
    {
        $elements = $this->elementString($data);
        $bits = $this->binary($elements->toEncodable());

        $dataChars = intdiv(strlen($bits), 12);
        $symbolChars = $dataChars + 1;

        $charWidths = [];
        for ($i = 0; $i < $dataChars; $i++) {
            $charWidths[] = self::valueWidths((int) bindec(substr($bits, $i * 12, 12)));
        }

        $checksum = 0;
        $weightRow = self::WEIGHT_ROWS[intdiv($dataChars - 2, 2)];
        foreach ($charWidths as $i => $widths) {
            foreach ($widths as $j => $width) {
                $checksum += $width * self::CHECKSUM_WEIGHTS[$weightRow[$i]][$j];
            }
        }
        $checkWidths = self::valueWidths(211 * ($symbolChars - 4) + $checksum % 211);

        $codeblocks = intdiv($symbolChars + 1, 2);
        $patternWidth = $codeblocks * 5 + $symbolChars * 8 + 4;
        $elementWidths = array_fill(0, $patternWidth, 0);

        $sequence = self::FINDER_SEQUENCES[intdiv($symbolChars - 1, 2) - 1];
        for ($i = 0; $i < $codeblocks; $i++) {
            foreach (self::FINDER_PATTERNS[$sequence[$i] - 1] as $j => $width) {
                $elementWidths[21 * $i + $j + 10] = $width;
            }
        }
        foreach ($checkWidths as $j => $width) {
            $elementWidths[$j + 2] = $width;
        }
        for ($i = 1; $i < $dataChars; $i += 2) {
            foreach ($charWidths[$i] as $j => $width) {
                $elementWidths[intdiv($i - 1, 2) * 21 + 23 + $j] = $width;
            }
        }
        for ($i = 0; $i < $dataChars; $i += 2) {
            for ($j = 0; $j < 8; $j++) {
                $elementWidths[intdiv($i, 2) * 21 + 15 + $j] = $charWidths[$i][7 - $j];
            }
        }

        if ($this->columns === null) {
            $elementWidths[0] = 1;
            $elementWidths[1] = 1;
            $elementWidths[$patternWidth - 2] = 1;
            $elementWidths[$patternWidth - 1] = 1;
            $row = trim(self::expand(array_values($elementWidths), false), '0');

            return LinearSymbol::fromBinary($row, $elements->toHri(), $this->name(), $this->quietZone());
        }

        return new StackedLinearSymbol(
            $this->stack(array_values($elementWidths), $patternWidth, $codeblocks, $dataChars),
            $elements->toHri(),
            $this->name(),
            $this->quietZone(),
        );
    }

    /**
     * Splits the element pattern into rows with separator patterns (ISO/IEC 24724 §7.2.8).
     *
     * @param list<int> $elements
     *
     * @return list<array{bits: string, height: int, flexible?: bool}>
     */
    private function stack(array $elements, int $patternWidth, int $codeblocks, int $dataChars): array
    {
        $columns = min((int) $this->columns, $codeblocks);
        $stackRows = intdiv($codeblocks + $columns - 1, $columns);

        /** @var array<int, string> $grid */
        $grid = [];
        $heights = [];
        $v2Latch = false;
        $currentBlock = 0;
        $rowIndex = 0;
        $width = 0;

        for ($currentRow = 1; $currentRow <= $stackRows; $currentRow++) {
            $numColumns = $currentRow * $columns > $codeblocks ? $codeblocks - $currentBlock : $columns;
            $sub = [1, 1];
            $specialCaseRow = false;

            // Last row partial, even-numbered, even columns and an odd number of finders.
            if ($currentRow === $stackRows && $numColumns !== $columns && $currentRow % 2 === 0
                && $columns % 2 === 0 && $numColumns % 2 === 1) {
                $specialCaseRow = true;
                $sub[0] = 2;
            }
            $leftToRight = $columns % 2 === 1 || $currentRow % 2 === 1 || $specialCaseRow;

            $reader = 0;
            $count = 2;
            do {
                $start = 2 + $currentBlock * 21;
                for ($j = 0; $j < 21; $j++) {
                    if ($start + $j < $patternWidth) {
                        $index = $leftToRight ? $j + $reader * 21 + 2 : (20 - $j) + ($numColumns - 1 - $reader) * 21 + 2;
                        $sub[$index] = $elements[$start + $j];
                    }
                    $count++;
                }
                $reader++;
                $currentBlock++;
            } while ($reader < $columns && $currentBlock < $codeblocks);

            for ($k = 0; $k < $count; $k++) {
                $sub[$k] ??= 0;
            }
            $sub[$count++] = 1;
            $sub[$count++] = 1;
            ksort($sub);

            $startWithBar = !($currentRow % 2 === 1 || $specialCaseRow);
            $row = self::expand(array_values($sub), $startWithBar);
            $grid[$rowIndex] = $row;
            $heights[$rowIndex] = 34;
            $writer = strlen($row);
            $width = max($width, $writer);

            if ($currentRow !== 1) {
                $oddLastRow = $currentRow === $stackRows && $dataChars % 2 === 0;
                $middle = str_repeat('0', 49 * $columns);
                for ($j = 5; $j < 49 * $columns; $j += 2) {
                    $middle[$j] = '1';
                }
                $grid[$rowIndex - 2] = $middle;
                $heights[$rowIndex - 2] = 1;
                $latch = $v2Latch;
                $grid[$rowIndex - 1] = self::separator($row, $writer, $reader, $specialCaseRow, $leftToRight, $oddLastRow, $latch);
                $heights[$rowIndex - 1] = 1;
            }
            if ($currentRow !== $stackRows) {
                $grid[$rowIndex + 1] = self::separator($row, $writer, $reader, false, $leftToRight, false, $v2Latch);
                $heights[$rowIndex + 1] = 1;
            }
            $rowIndex += 4;
        }

        ksort($grid);
        $rows = [];
        foreach ($grid as $index => $bits) {
            $rows[] = $heights[$index] === 34
                ? ['bits' => substr($bits, 0, $width), 'height' => 34, 'flexible' => true]
                : ['bits' => substr($bits, 0, $width), 'height' => 1];
        }

        return $rows;
    }

    /**
     * Separator pattern between Expanded Stacked rows: the complement of the
     * adjacent data row, with alternating modules over its finder patterns.
     */
    private static function separator(
        string $row,
        int $width,
        int $columns,
        bool $specialCaseRow,
        bool $leftToRight,
        bool $oddLastRow,
        bool &$v2Latch,
    ): string {
        $special = $specialCaseRow ? 1 : 0;
        $separator = str_repeat('0', $width);
        for ($j = 4 + $special; $j < $width - 4; $j++) {
            $separator[$j] = $row[$j] === '1' ? '0' : '1';
        }

        $latch = $v2Latch;
        $spaceLatch = false;
        for ($j = 0; $j < $columns; $j++) {
            $k = 49 * $j + 19 + $special;
            if ($leftToRight) {
                $range = $latch ? range(2, 14) : range(0, 12);
            } else {
                if ($oddLastRow) {
                    $k -= 17;
                }
                $range = $latch ? range(14, 2) : range(12, 0);
            }
            foreach ($range as $i) {
                $position = $i + $k;
                if ($position < 0 || $position >= $width) {
                    continue;
                }
                if ($row[$position] === '1') {
                    $separator[$position] = '0';
                    $spaceLatch = false;
                } else {
                    $separator[$position] = $spaceLatch ? '0' : '1';
                    $spaceLatch = !$spaceLatch;
                }
            }
            $latch = !$latch;
        }
        $v2Latch = $latch;

        return $separator;
    }

    /**
     * @return list<int>
     */
    private static function valueWidths(int $value): array
    {
        $group = 0;
        while ($group < 4 && $value >= self::G_SUM[$group + 1]) {
            $group++;
        }
        $value -= self::G_SUM[$group];

        return self::characterWidths(
            intdiv($value, self::T_EVEN[$group]),
            $value % self::T_EVEN[$group],
            self::MODULES_ODD[$group],
            17 - self::MODULES_ODD[$group],
            4,
            self::WIDEST_ODD[$group],
            true,
        );
    }

    /**
     * @throws InvalidDataException
     */
    private function elementString(string $data): ElementString
    {
        try {
            return ElementString::parse($data, $this->checkAssociations);
        } catch (Gs1Exception $e) {
            throw new InvalidDataException(sprintf('%s: %s', $this->name(), $e->getMessage()), 0, $e);
        }
    }

    /**
     * The binary data stream (ISO/IEC 24724 §7.2.5), padded to whole 12-bit symbol characters.
     *
     * @throws InvalidDataException
     */
    private function binary(string $source): string
    {
        $length = strlen($source);
        if ($length > 77) {
            throw InvalidDataException::invalidLength($this->name(), 'at most 77 characters of encoded data', $length);
        }

        $method = $this->encodingMethod($source);
        [$bits, $readPosition] = match (true) {
            $method === 1 => ['01' . 'XX', 16],                                     // linkage 0, "1XX"
            $method === 2 => ['0' . '00XX', 0],                                      // "00XX"
            $method === 3, $method === 4 => ['0' . sprintf('%04b', 4 + $method - 3), 26],
            $method === 5 => ['0' . '01100XX', 20],
            $method === 6 => ['0' . '01101XX', 23],
            default => ['0' . sprintf('%07b', 56 + $method - 7), $length],
        };

        $gtinDigits = static function () use ($source): string {
            $out = '';
            for ($i = 3; $i < 15; $i += 3) {
                $out .= sprintf('%010b', (int) substr($source, $i, 3));
            }

            return $out;
        };

        if ($method === 1) {
            $bits .= sprintf('%04b', (int) $source[2]) . $gtinDigits();
        } elseif ($method === 3 || $method === 4) {
            $weight = (int) substr($source, 20, 6);
            $bits .= $gtinDigits() . sprintf('%015b', $method === 4 && $source[19] === '3' ? $weight + 10000 : $weight);
        } elseif ($method === 5 || $method === 6) {
            $bits .= $gtinDigits() . sprintf('%02b', (int) $source[19]);
            if ($method === 6) {
                $bits .= sprintf('%010b', (int) substr($source, 20, 3));
            }
        } elseif ($method >= 7) {
            $weight = (int) ($source[19] . substr($source, 21, 5));
            $date = $length === 34 ? (int) self::date($source, 28) : 38400;
            $bits .= $gtinDigits() . sprintf('%020b', $weight) . sprintf('%016b', $date);
        }

        $mode = self::NUMERIC;
        $lastDigit = null;
        if ($readPosition < $length) {
            [$general, $mode, $lastDigit] = $this->generalField(substr($source, $readPosition));
            $bits .= $general;
        }

        $symbolChars = $this->symbolCharacters(strlen($bits));
        $remainder = 12 * ($symbolChars - 1) - strlen($bits);

        if ($lastDigit !== null) {
            // A final odd digit (ISO/IEC 24724 7.2.5.5.1 c).
            $bits .= $remainder >= 4 && $remainder <= 6
                ? sprintf('%04b', (int) $lastDigit + 1)
                : sprintf('%07b', (int) $lastDigit * 11 + 10 + 8);
            $symbolChars = $this->symbolCharacters(strlen($bits));
            $remainder = 12 * ($symbolChars - 1) - strlen($bits);
        }

        if (strlen($bits) > 252) {
            throw new InvalidDataException(sprintf(
                '%s: data too long, needs %d symbol characters (maximum 21).',
                $this->name(),
                intdiv(strlen($bits) + 11, 12),
            ));
        }

        // Padding (7.2.5.5.4): a numeric latch if needed, then "00100" repeated.
        if ($mode === self::NUMERIC) {
            $bits .= '0000';
            $remainder -= 4;
        }
        for (; $remainder > 0; $remainder -= 5) {
            $bits .= '00100';
        }
        $bits = substr($bits, 0, 12 * ($symbolChars - 1));

        // The variable length field: symbol count parity and whether it exceeds 14.
        $variable = ($symbolChars % 2 === 1 ? '1' : '0') . ($symbolChars > 14 ? '1' : '0');
        $position = match ($method) {
            1 => 2,
            2 => 3,
            5, 6 => 6,
            default => null,
        };
        if ($position !== null) {
            $bits = substr_replace($bits, $variable, $position, 2);
        }

        return $bits;
    }

    private function symbolCharacters(int $bitCount): int
    {
        $symbolChars = intdiv($bitCount + 11, 12) + 1;
        if ($this->columns !== null && $symbolChars % ($this->columns * 2) === 1) {
            $symbolChars++;
        }

        return max(4, $symbolChars);
    }

    /**
     * Encoding method (ISO/IEC 24724 Table 10): 1 for (01)+other, 2 for no (01), 3-14 for compressed weight/price/date.
     */
    private function encodingMethod(string $source): int
    {
        $length = strlen($source);
        if ($length < 16 || !str_starts_with($source, '01')) {
            return 2;
        }
        if ($length < 20 || $source[2] !== '9' || $source[16] !== '3') {
            return 1;
        }

        $ai = substr($source, 17, 2);
        if (($ai === '10' || $ai === '20') && $length >= 26) {
            $weight = (int) substr($source, 20, 6);
            if (!ctype_digit(substr($source, 20, 6)) || $weight > 99999) {
                return 1;
            }
            $metric = $ai === '10';
            if ($length === 26) {
                if ($metric) {
                    return $source[19] === '3' && $weight <= 32767 ? 3 : 7;
                }

                return ($source[19] === '2' && $weight <= 9999) || ($source[19] === '3' && $weight <= 22767) ? 4 : 8;
            }
            if ($length === 34 && $source[26] === '1' && str_contains('1357', $source[27]) && self::date($source, 28) !== null) {
                return ($metric ? 6 : 7) + (int) $source[27];
            }

            return 1;
        }

        if ($source[17] === '9' && $source[19] >= '0' && $source[19] <= '3') {
            if ($source[18] === '2') {
                return 5;
            }
            if ($source[18] === '3' && $length >= 23 && ctype_digit(substr($source, 20, 3))) {
                return 6;
            }
        }

        return 1;
    }

    private static function date(string $source, int $position): ?int
    {
        $yy = (int) substr($source, $position, 2);
        $mm = (int) substr($source, $position + 2, 2);
        $dd = (int) substr($source, $position + 4, 2);

        return $mm >= 1 && $mm <= 12 && $dd <= 31 ? $yy * 384 + ($mm - 1) * 32 + $dd : null;
    }

    /**
     * General-purpose data compaction (ISO/IEC 24724 §7.2.5.5): numeric, alphanumeric
     * and ISO/IEC 646 modes, with GS (FNC1) separating fields.
     *
     * @return array{string, string, ?string} Bits, final mode, and a trailing odd digit if any.
     *
     * @throws InvalidDataException
     */
    private function generalField(string $field): array
    {
        $bits = '';
        $mode = self::NUMERIC;
        $lastDigit = null;
        $length = strlen($field);

        for ($i = 0; $i < $length;) {
            $type = self::characterType($field[$i]);
            if ($type === null) {
                throw InvalidDataException::invalidCharacter($this->name(), $field[$i], $i);
            }

            if ($mode === self::NUMERIC) {
                if ($i < $length - 1) {
                    if ($type !== self::NUMERIC || self::characterType($field[$i + 1]) !== self::NUMERIC) {
                        $bits .= '0000';
                        $mode = self::ALPHANUMERIC;
                    } else {
                        $d1 = $field[$i] === ElementString::GS ? 10 : (int) $field[$i];
                        $d2 = $field[$i + 1] === ElementString::GS ? 10 : (int) $field[$i + 1];
                        $bits .= sprintf('%07b', 11 * $d1 + $d2 + 8);
                        $i += 2;
                    }
                } elseif ($type !== self::NUMERIC) {
                    $bits .= '0000';
                    $mode = self::ALPHANUMERIC;
                } else {
                    $lastDigit = $field[$i];
                    $i++;
                }
                continue;
            }

            if ($field[$i] === ElementString::GS) {
                $bits .= '01111';
                $mode = self::NUMERIC;
                $i++;
                continue;
            }

            if ($mode === self::ALPHANUMERIC) {
                if ($type === self::ISO646) {
                    $bits .= '00100';
                    $mode = self::ISO646;
                } elseif (self::next($field, $i, 6, [self::NUMERIC]) || self::nextTerminate($field, $i, 4, 5)) {
                    $bits .= '000';
                    $mode = self::NUMERIC;
                } elseif (ctype_digit($field[$i])) {
                    $bits .= sprintf('%05b', ord($field[$i]) - 43);
                    $i++;
                } elseif (ctype_upper($field[$i])) {
                    $bits .= sprintf('%06b', ord($field[$i]) - 33);
                    $i++;
                } else {
                    $bits .= sprintf('%06b', (int) strpos(self::ALPHANUMERIC_PUNCTUATION, $field[$i]) + 58);
                    $i++;
                }
                continue;
            }

            // ISO/IEC 646 mode.
            $noIsoAhead = self::nextNone($field, $i, 10, self::ISO646);
            if ($noIsoAhead && self::next($field, $i, 4, [self::NUMERIC])) {
                $bits .= '000';
                $mode = self::NUMERIC;
            } elseif ($noIsoAhead && self::next($field, $i, 5, [self::ALPHANUMERIC, self::NUMERIC])) {
                $bits .= '00100';
                $mode = self::ALPHANUMERIC;
            } elseif (ctype_digit($field[$i])) {
                $bits .= sprintf('%05b', ord($field[$i]) - 43);
                $i++;
            } elseif (ctype_upper($field[$i])) {
                $bits .= sprintf('%07b', ord($field[$i]) - 1);
                $i++;
            } elseif (ctype_lower($field[$i])) {
                $bits .= sprintf('%07b', ord($field[$i]) - 7);
                $i++;
            } else {
                $bits .= sprintf('%08b', (int) strpos(self::ISO646_PUNCTUATION, $field[$i]) + 232);
                $i++;
            }
        }

        return [$bits, $mode, $lastDigit];
    }

    private static function characterType(string $character): ?string
    {
        return match (true) {
            $character === ElementString::GS, ctype_digit($character) => self::NUMERIC,
            ctype_upper($character), str_contains(self::ALPHANUMERIC_PUNCTUATION, $character) => self::ALPHANUMERIC,
            ctype_lower($character), str_contains(self::ISO646_PUNCTUATION, $character) => self::ISO646,
            default => null,
        };
    }

    /**
     * Whether the next $count characters from $i exist and all have one of $types.
     *
     * @param list<string> $types
     */
    private static function next(string $field, int $i, int $count, array $types): bool
    {
        if ($i + $count > strlen($field)) {
            return false;
        }
        for ($j = $i; $j < $i + $count; $j++) {
            if (!in_array(self::characterType($field[$j]), $types, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the field ends within $max characters and the rest (at least $min) are all numeric.
     */
    private static function nextTerminate(string $field, int $i, int $min, int $max): bool
    {
        $remaining = strlen($field) - $i;
        if ($remaining > $max || $remaining < $min) {
            return false;
        }

        return self::next($field, $i, $remaining, [self::NUMERIC]);
    }

    /**
     * Whether none of the next $count characters (or up to the end) has type $type.
     */
    private static function nextNone(string $field, int $i, int $count, string $type): bool
    {
        for ($j = $i, $end = min(strlen($field), $i + $count); $j < $end; $j++) {
            if (self::characterType($field[$j]) === $type) {
                return false;
            }
        }

        return true;
    }
}
