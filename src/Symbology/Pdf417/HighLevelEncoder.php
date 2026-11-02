<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\Pdf417;

/**
 * Converts bytes into PDF417 data codewords using text, byte and numeric
 * compaction (ISO/IEC 15438 §5.4), switching modes where it shortens the
 * result: runs of 13+ digits use numeric compaction, runs of 5+ text
 * characters use text compaction, and everything else uses byte compaction.
 *
 * @internal
 */
final class HighLevelEncoder
{
    public const LATCH_TEXT = 900;
    public const LATCH_BYTE = 901;
    public const LATCH_NUMERIC = 902;
    public const SHIFT_BYTE = 913;
    public const LATCH_BYTE_SIX = 924;
    public const ECI_GENERAL = 925;
    public const ECI_USER = 926;
    public const ECI_CHARSET = 927;

    private const MIN_NUMERIC_RUN = 13;
    private const MIN_TEXT_RUN = 5;

    private const MODE_TEXT = 0;
    private const MODE_BYTE = 1;
    private const MODE_NUMERIC = 2;

    private const ALPHA = 0;
    private const LOWER = 1;
    private const MIXED = 2;
    private const PUNCT = 3;

    /** Text compaction "mixed" sub-mode values (25 = PL, 27 = LL, 28 = AL, 29 = PS). */
    private const MIXED_CHARS = [
        '0' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7, '8' => 8, '9' => 9,
        '&' => 10, "\r" => 11, "\t" => 12, ',' => 13, ':' => 14, '#' => 15, '-' => 16, '.' => 17, '$' => 18,
        '/' => 19, '+' => 20, '%' => 21, '*' => 22, '=' => 23, '^' => 24, ' ' => 26,
    ];

    /** Text compaction "punctuation" sub-mode values (29 = AL). */
    private const PUNCT_CHARS = [
        ';' => 0, '<' => 1, '>' => 2, '@' => 3, '[' => 4, '\\' => 5, ']' => 6, '_' => 7, '`' => 8, '~' => 9,
        '!' => 10, "\r" => 11, "\t" => 12, ',' => 13, ':' => 14, "\n" => 15, '-' => 16, '.' => 17, '$' => 18,
        '/' => 19, '"' => 20, '|' => 21, '*' => 22, '(' => 23, ')' => 24, '?' => 25, '{' => 26, '}' => 27, "'" => 28,
    ];

    private const PL = 25;
    private const LL = 27;
    private const ML = 28;
    private const AL = 28;
    private const AS = 27;
    private const PS = 29;
    private const PUNCT_AL = 29;

    /**
     * @param bool     $startInByteMode MicroPDF417 starts in byte compaction, PDF417 in text compaction.
     * @param int|null $eci             ECI assignment number to emit first, if any.
     *
     * @return list<int>
     */
    public static function encode(string $data, ?int $eci = null, bool $startInByteMode = false): array
    {
        $codewords = $eci === null ? [] : self::eci($eci);

        $mode = $startInByteMode ? self::MODE_BYTE : self::MODE_TEXT;
        $submode = self::ALPHA;
        $length = strlen($data);
        $position = 0;

        while ($position < $length) {
            $digits = strspn($data, '0123456789', $position);

            if ($digits >= self::MIN_NUMERIC_RUN) {
                $codewords[] = self::LATCH_NUMERIC;
                array_push($codewords, ...self::numeric(substr($data, $position, $digits)));
                $mode = self::MODE_NUMERIC;
                $position += $digits;
                continue;
            }

            $textRun = self::textRunLength($data, $position);
            if ($textRun >= self::MIN_TEXT_RUN || $digits === $length) {
                if ($mode !== self::MODE_TEXT) {
                    $codewords[] = self::LATCH_TEXT;
                    $mode = self::MODE_TEXT;
                    $submode = self::ALPHA;
                }
                $submode = self::text(substr($data, $position, $textRun), $submode, $codewords);
                $position += $textRun;
                continue;
            }

            $byteRun = max(1, self::byteRunLength($data, $position));
            $bytes = substr($data, $position, $byteRun);
            if ($byteRun === 1 && $mode === self::MODE_TEXT) {
                $codewords[] = self::SHIFT_BYTE;
                $codewords[] = ord($bytes);
            } else {
                array_push($codewords, ...self::bytes($bytes));
                $mode = self::MODE_BYTE;
            }
            $position += $byteRun;
        }

        return $codewords;
    }

    /**
     * @return list<int>
     */
    public static function eci(int $eci): array
    {
        return match (true) {
            $eci < 0 => throw new \InvalidArgumentException('ECI numbers cannot be negative.'),
            $eci <= 899 => [self::ECI_CHARSET, $eci],
            $eci <= 810899 => [self::ECI_USER, intdiv($eci, 900) - 1, $eci % 900],
            $eci <= 811799 => [self::ECI_GENERAL, $eci - 810900],
            default => throw new \InvalidArgumentException('ECI numbers range from 0 to 811799.'),
        };
    }

    /**
     * Byte compaction: groups of six bytes become five base-900 codewords.
     *
     * @return list<int>
     */
    public static function bytes(string $bytes): array
    {
        $length = strlen($bytes);
        $codewords = [$length % 6 === 0 ? self::LATCH_BYTE_SIX : self::LATCH_BYTE];

        $offset = 0;
        for (; $offset + 6 <= $length; $offset += 6) {
            $value = 0;
            for ($i = 0; $i < 6; $i++) {
                $value = ($value << 8) | ord($bytes[$offset + $i]);
            }
            $group = [];
            for ($i = 0; $i < 5; $i++) {
                $group[] = $value % 900;
                $value = intdiv($value, 900);
            }
            array_push($codewords, ...array_reverse($group));
        }
        for (; $offset < $length; $offset++) {
            $codewords[] = ord($bytes[$offset]);
        }

        return $codewords;
    }

    /**
     * Numeric compaction: each group of up to 44 digits, prefixed with "1",
     * is converted to base 900 (arbitrary precision, no bcmath/gmp needed).
     *
     * @return list<int>
     */
    public static function numeric(string $digits): array
    {
        $codewords = [];
        foreach (str_split($digits, 44) as $group) {
            array_push($codewords, ...self::toBase900('1' . $group));
        }

        return $codewords;
    }

    /**
     * Repeated long division of a decimal string by 900.
     *
     * @return list<int>
     */
    private static function toBase900(string $decimal): array
    {
        $digits = array_map('intval', str_split($decimal));
        $result = [];

        while ($digits !== []) {
            $quotient = [];
            $remainder = 0;
            foreach ($digits as $digit) {
                $remainder = $remainder * 10 + $digit;
                $q = intdiv($remainder, 900);
                $remainder %= 900;
                if ($q !== 0 || $quotient !== []) {
                    $quotient[] = $q;
                }
            }
            $result[] = $remainder;
            $digits = $quotient;
        }

        return array_reverse($result);
    }

    /**
     * Text compaction of $text (which must be all text characters), appending
     * to $codewords. Returns the sub-mode in effect afterwards.
     *
     * @param list<int> $codewords
     */
    private static function text(string $text, int $submode, array &$codewords): int
    {
        $values = [];
        $length = strlen($text);

        for ($i = 0; $i < $length;) {
            $ch = $text[$i];
            $isUpper = ($ch >= 'A' && $ch <= 'Z') || $ch === ' ';
            $isLower = ($ch >= 'a' && $ch <= 'z') || $ch === ' ';
            $isMixed = isset(self::MIXED_CHARS[$ch]);
            $isPunct = isset(self::PUNCT_CHARS[$ch]);

            switch ($submode) {
                case self::ALPHA:
                    if ($isUpper) {
                        $values[] = $ch === ' ' ? 26 : ord($ch) - 65;
                        $i++;
                    } elseif ($isLower) {
                        $values[] = self::LL;
                        $submode = self::LOWER;
                    } elseif ($isMixed) {
                        $values[] = self::ML;
                        $submode = self::MIXED;
                    } else {
                        $values[] = self::PS;
                        $values[] = self::PUNCT_CHARS[$ch];
                        $i++;
                    }
                    break;

                case self::LOWER:
                    if ($isLower) {
                        $values[] = $ch === ' ' ? 26 : ord($ch) - 97;
                        $i++;
                    } elseif ($isUpper) {
                        $values[] = self::AS;
                        $values[] = ord($ch) - 65;
                        $i++;
                    } elseif ($isMixed) {
                        $values[] = self::ML;
                        $submode = self::MIXED;
                    } else {
                        $values[] = self::PS;
                        $values[] = self::PUNCT_CHARS[$ch];
                        $i++;
                    }
                    break;

                case self::MIXED:
                    if ($isMixed) {
                        $values[] = self::MIXED_CHARS[$ch];
                        $i++;
                    } elseif ($isUpper) {
                        $values[] = self::AL;
                        $submode = self::ALPHA;
                    } elseif ($isLower) {
                        $values[] = self::LL;
                        $submode = self::LOWER;
                    } elseif ($i + 1 < $length && isset(self::PUNCT_CHARS[$text[$i + 1]])) {
                        $values[] = self::PL;
                        $submode = self::PUNCT;
                    } else {
                        $values[] = self::PS;
                        $values[] = self::PUNCT_CHARS[$ch];
                        $i++;
                    }
                    break;

                default: // PUNCT
                    if ($isPunct) {
                        $values[] = self::PUNCT_CHARS[$ch];
                        $i++;
                    } else {
                        $values[] = self::PUNCT_AL;
                        $submode = self::ALPHA;
                    }
            }
        }

        if (count($values) % 2 !== 0) {
            // Pad with PS (or AL in punctuation sub-mode, which also returns to alpha).
            $values[] = 29;
            if ($submode === self::PUNCT) {
                $submode = self::ALPHA;
            }
        }

        for ($i = 0, $count = count($values); $i < $count; $i += 2) {
            $codewords[] = $values[$i] * 30 + $values[$i + 1];
        }

        return $submode;
    }

    private static function isText(string $ch): bool
    {
        return $ch === "\t" || $ch === "\n" || $ch === "\r" || ($ch >= ' ' && $ch <= '~');
    }

    /**
     * Number of text characters from $start, stopping before a run of 13+ digits.
     */
    private static function textRunLength(string $data, int $start): int
    {
        $length = strlen($data);
        $index = $start;

        while ($index < $length) {
            $digits = min(self::MIN_NUMERIC_RUN, strspn($data, '0123456789', $index));
            if ($digits >= self::MIN_NUMERIC_RUN) {
                return $index - $start;
            }
            if ($digits > 0) {
                $index += $digits;
                continue;
            }
            if (!self::isText($data[$index])) {
                break;
            }
            $index++;
        }

        return $index - $start;
    }

    /**
     * Number of bytes from $start before a numeric or text run worth switching to.
     */
    private static function byteRunLength(string $data, int $start): int
    {
        $length = strlen($data);
        $index = $start;

        while ($index < $length) {
            if (strspn($data, '0123456789', $index) >= self::MIN_NUMERIC_RUN) {
                return $index - $start;
            }
            $text = 0;
            while ($text < self::MIN_TEXT_RUN && $index + $text < $length && self::isText($data[$index + $text])) {
                $text++;
            }
            if ($text >= self::MIN_TEXT_RUN) {
                return $index - $start;
            }
            $index++;
        }

        return $index - $start;
    }
}
