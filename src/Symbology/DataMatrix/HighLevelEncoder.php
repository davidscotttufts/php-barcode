<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\DataMatrix;

/**
 * Converts a byte string into Data Matrix data codewords, switching between
 * the ASCII, C40, Text, X12, EDIFACT and Base 256 encodation schemes using the
 * look-ahead algorithm of ISO/IEC 16022 Annex P.
 *
 * The structure follows the well-known implementation in ZXing (Apache-2.0),
 * with exact integer arithmetic for the look-ahead and stricter end-of-data
 * handling so that every switch the encoder makes is decodable.
 *
 * @internal
 */
final class HighLevelEncoder
{
    public const ASCII = 0;
    public const C40 = 1;
    public const TEXT = 2;
    public const X12 = 3;
    public const EDIFACT = 4;
    public const BASE256 = 5;

    private const UNLATCH = 254;
    private const PAD = 129;
    private const UPPER_SHIFT = 235;
    private const FNC1 = 232;
    private const GS = 0x1D;

    /** @var list<int> */
    private array $message;
    private int $length;
    private int $pos = 0;

    /** @var list<int> */
    private array $codewords = [];

    private int $mode = self::ASCII;
    private ?int $newMode = null;

    /** Position where a latch made no progress; ASCII must not latch there again. */
    private int $noLatchAt = -1;

    /**
     * @param \Closure(int): ?int $capacity Data capacity of the smallest allowed symbol holding n codewords, or null if none does.
     * @param bool $gs1 Encode ASCII GS (0x1D) as FNC1 separators.
     */
    public function __construct(
        string $data,
        private readonly \Closure $capacity,
        private readonly bool $gs1 = false,
    ) {
        $this->message = array_map('ord', str_split($data));
        $this->length = count($this->message);
    }

    /**
     * @param list<int> $prefix Codewords to emit first (structured append, FNC1, macro, ECI).
     *
     * @return list<int> Data codewords padded to the capacity of the chosen symbol, or null-capacity overflow.
     *
     * @throws \OverflowException when no allowed symbol is large enough
     */
    public function encode(array $prefix = []): array
    {
        $this->codewords = $prefix;

        while ($this->pos < $this->length) {
            $start = $this->pos;
            $mode = $this->mode;
            match ($this->mode) {
                self::ASCII => $this->encodeAscii(),
                self::C40 => $this->encodeC40(false),
                self::TEXT => $this->encodeC40(true),
                self::X12 => $this->encodeX12(),
                self::EDIFACT => $this->encodeEdifact(),
                default => $this->encodeBase256(),
            };
            if ($this->newMode !== null) {
                $this->mode = $this->newMode;
                $this->newMode = null;
            }
            if ($mode !== self::ASCII && $this->pos === $start && $this->mode === self::ASCII) {
                $this->noLatchAt = $start;
            }
        }

        $count = count($this->codewords);
        $capacity = ($this->capacity)($count) ?? throw new \OverflowException('Data too long.');

        if ($count < $capacity && in_array($this->mode, [self::C40, self::TEXT, self::X12], true)) {
            $this->codewords[] = self::UNLATCH;
        }

        if (count($this->codewords) < $capacity) {
            $this->codewords[] = self::PAD;
        }
        while (count($this->codewords) < $capacity) {
            $position = count($this->codewords) + 1;
            $value = self::PAD + (149 * $position) % 253 + 1;
            $this->codewords[] = $value <= 254 ? $value : $value - 254;
        }

        return $this->codewords;
    }

    // ------------------------------------------------------------------ ASCII

    private function encodeAscii(): void
    {
        $c = $this->message[$this->pos];

        if ($this->isDigit($c) && $this->pos + 1 < $this->length && $this->isDigit($this->message[$this->pos + 1])) {
            $this->codewords[] = ($c - 48) * 10 + ($this->message[$this->pos + 1] - 48) + 130;
            $this->pos += 2;

            return;
        }

        $newMode = $this->pos === $this->noLatchAt ? self::ASCII : $this->lookAhead($this->pos, self::ASCII);
        if ($newMode !== self::ASCII) {
            $this->codewords[] = match ($newMode) {
                self::C40 => 230,
                self::BASE256 => 231,
                self::X12 => 238,
                self::TEXT => 239,
                default => 240, // EDIFACT
            };
            $this->newMode = $newMode;

            return;
        }

        $this->writeAsciiChar($c);
        $this->pos++;
    }

    private function writeAsciiChar(int $c): void
    {
        if ($this->isFnc1($c)) {
            $this->codewords[] = self::FNC1;
        } elseif ($c >= 128) {
            $this->codewords[] = self::UPPER_SHIFT;
            $this->codewords[] = $c - 127;
        } else {
            $this->codewords[] = $c + 1;
        }
    }

    // -------------------------------------------------------------- C40/Text

    private function encodeC40(bool $text): void
    {
        $mode = $text ? self::TEXT : self::C40;
        /** @var list<int> $buffer */
        $buffer = [];
        /** @var list<int> $sizes */
        $sizes = [];

        while ($this->pos < $this->length) {
            $values = $this->c40Values($this->message[$this->pos], $text);
            $this->pos++;
            array_push($buffer, ...$values);
            $sizes[] = count($values);

            if ($this->pos < $this->length && count($buffer) % 3 === 0
                && $this->lookAhead($this->pos, $mode) !== $mode) {
                break;
            }
        }

        // End of segment: never leave a partial triplet that a decoder could misread.
        $rest = count($buffer) % 3;
        if ($rest === 1) {
            $triplets = count($this->codewords) + intdiv(count($buffer), 3) * 2;
            $available = $this->capacityFor($triplets) - $triplets;
            $last = $this->message[$this->pos - 1];
            if ($this->pos === $this->length && $available === 1 && end($sizes) === 1 && $last < 128) {
                // One value left and one codeword of room: it may be ASCII-encoded without an unlatch.
                array_pop($buffer);
                $this->pos--;
                $this->writeTriplets($buffer);
                $this->newMode = self::ASCII;
                $this->mode = self::ASCII;

                return;
            }
        }
        while (count($buffer) % 3 === 1) {
            $size = (int) array_pop($sizes);
            array_splice($buffer, -$size);
            $this->pos--;
        }
        if (count($buffer) % 3 === 2) {
            $buffer[] = 0; // Shift 1 as padding
        }

        $this->writeTriplets($buffer);
        $this->unlatchUnlessFull();
    }

    /**
     * @param list<int> $values
     */
    private function writeTriplets(array $values): void
    {
        foreach (array_chunk($values, 3) as [$a, $b, $c]) {
            $v = 1600 * $a + 40 * $b + $c + 1;
            $this->codewords[] = $v >> 8;
            $this->codewords[] = $v & 0xFF;
        }
    }

    private function unlatchUnlessFull(): void
    {
        $count = count($this->codewords);
        if ($this->pos < $this->length || $this->capacityFor($count) > $count) {
            $this->codewords[] = self::UNLATCH;
        }
        $this->newMode = self::ASCII;
        $this->mode = self::ASCII;
    }

    /**
     * @return list<int>
     */
    private function c40Values(int $c, bool $text): array
    {
        if ($this->isFnc1($c)) {
            return [1, 27];
        }
        if ($c >= 128) {
            return [1, 30, ...$this->c40Values($c - 128, $text)];
        }
        if ($c === 32) {
            return [3];
        }
        if ($c >= 48 && $c <= 57) {
            return [$c - 44];
        }
        if (!$text && $c >= 65 && $c <= 90) {
            return [$c - 51];
        }
        if ($text && $c >= 97 && $c <= 122) {
            return [$c - 83];
        }
        if ($c < 32) {
            return [0, $c];
        }
        if ($c <= 47) {
            return [1, $c - 33];
        }
        if ($c <= 64) {
            return [1, $c - 43];
        }
        if ($c >= 91 && $c <= 95) {
            return [1, $c - 69];
        }
        if ($text && $c === 96) {
            return [2, 0];
        }
        if ($text && $c <= 90) { // A-Z
            return [2, $c - 64];
        }

        return [2, $c - 96];
    }

    // ------------------------------------------------------------------- X12

    private function encodeX12(): void
    {
        /** @var list<int> $buffer */
        $buffer = [];

        while ($this->pos < $this->length) {
            $c = $this->message[$this->pos];
            if (!$this->isNativeX12($c)) {
                break;
            }
            $buffer[] = match (true) {
                $c === 13 => 0,
                $c === 42 => 1,
                $c === 62 => 2,
                $c === 32 => 3,
                $c <= 57 => $c - 44,
                default => $c - 51,
            };
            $this->pos++;

            if (count($buffer) === 3) {
                $this->writeTriplets($buffer);
                $buffer = [];
                if ($this->lookAhead($this->pos, self::X12) !== self::X12) {
                    break;
                }
            }
        }

        // Leftover characters are re-encoded in ASCII.
        $this->pos -= count($buffer);
        $count = count($this->codewords);
        $available = $this->capacityFor($count) - $count;
        $remaining = $this->length - $this->pos;

        $implicitAscii = $remaining === 1 && $available === 1 && $this->message[$this->pos] < 128;
        if (!($implicitAscii || ($remaining === 0 && $available === 0))) {
            $this->codewords[] = self::UNLATCH;
        }
        $this->newMode = self::ASCII;
        $this->mode = self::ASCII;
    }

    // --------------------------------------------------------------- EDIFACT

    private function encodeEdifact(): void
    {
        /** @var list<int> $buffer */
        $buffer = [];

        while ($this->pos < $this->length) {
            $c = $this->message[$this->pos];
            if (!$this->isNativeEdifact($c)) {
                break;
            }
            $buffer[] = $c & 0x3F;
            $this->pos++;

            if (count($buffer) === 4) {
                $this->writeEdifact($buffer);
                $buffer = [];
                if ($this->lookAhead($this->pos, self::EDIFACT) !== self::EDIFACT) {
                    break;
                }
            }
        }

        $this->newMode = self::ASCII;
        $this->mode = self::ASCII;
        $restChars = count($buffer);
        $buffer[] = 31; // Unlatch

        $count = count($this->codewords);
        $remaining = $this->length - $this->pos;
        $remainingIsAscii = $this->allSingleAscii($this->pos, $this->length);

        if ($restChars === 0) {
            $available = $this->capacityFor($count) - $count;
            if ($remaining > $available) {
                $available = $this->capacityFor($count + 1) - $count;
            }
            if ($remaining <= $available && $available <= 2 && $remainingIsAscii) {
                return; // The symbol ends within two codewords: ASCII may follow without an unlatch.
            }
            $this->writeEdifact($buffer);

            return;
        }

        if ($remaining === 0 && $restChars <= 2) {
            $available = $this->capacityFor($count + $restChars) - $count;
            if ($available < 3) {
                $this->pos -= $restChars; // Final one or two characters in ASCII, no unlatch.

                return;
            }
        }

        $this->writeEdifact($buffer);
    }

    /**
     * @param list<int> $values Up to four 6-bit values.
     */
    private function writeEdifact(array $values): void
    {
        $n = count($values);
        $padded = array_pad($values, 4, 0);
        $v = ($padded[0] << 18) | ($padded[1] << 12) | ($padded[2] << 6) | $padded[3];
        $bytes = [($v >> 16) & 0xFF, ($v >> 8) & 0xFF, $v & 0xFF];
        array_push($this->codewords, ...array_slice($bytes, 0, min($n, 3)));
    }

    // ------------------------------------------------------------- Base 256

    private function encodeBase256(): void
    {
        /** @var list<int> $buffer */
        $buffer = [];

        while ($this->pos < $this->length) {
            $buffer[] = $this->message[$this->pos];
            $this->pos++;
            if ($this->lookAhead($this->pos, self::BASE256) !== self::BASE256) {
                $this->newMode = self::ASCII;
                break;
            }
        }

        $dataCount = count($buffer);
        $current = count($this->codewords) + $dataCount + 1;
        $mustPad = $this->capacityFor($current) > $current;

        if ($this->pos < $this->length || $mustPad) {
            $lengthField = match (true) {
                $dataCount <= 249 => [$dataCount],
                $dataCount <= 1555 => [intdiv($dataCount, 250) + 249, $dataCount % 250],
                default => throw new \OverflowException('Data too long.'),
            };
        } else {
            $lengthField = [0]; // 0 = "until the end of the symbol"
        }

        foreach ([...$lengthField, ...$buffer] as $byte) {
            $position = count($this->codewords) + 1;
            $value = $byte + (149 * $position) % 255 + 1;
            $this->codewords[] = $value <= 255 ? $value : $value - 256;
        }
    }

    // ------------------------------------------------------------ look-ahead

    /**
     * ISO/IEC 16022 Annex P: which scheme should encode the data starting at $start.
     */
    private function lookAhead(int $start, int $current): int
    {
        $new = $this->lookAheadIntern($start, $current);

        if ($current === self::X12 && $new === self::X12) {
            for ($i = $start, $end = min($start + 3, $this->length); $i < $end; $i++) {
                if (!$this->isNativeX12($this->message[$i])) {
                    return self::ASCII;
                }
            }
        } elseif ($current === self::EDIFACT && $new === self::EDIFACT) {
            for ($i = $start, $end = min($start + 4, $this->length); $i < $end; $i++) {
                if (!$this->isNativeEdifact($this->message[$i])) {
                    return self::ASCII;
                }
            }
        }

        return $new;
    }

    /**
     * Costs are tracked in twelfths of a codeword so the arithmetic is exact.
     */
    private function lookAheadIntern(int $start, int $current): int
    {
        if ($start >= $this->length) {
            return $current;
        }

        $counts = $current === self::ASCII ? [0, 12, 12, 12, 12, 15] : [12, 24, 24, 24, 24, 27];
        $counts[$current] = 0;

        for ($processed = 0; ;) {
            if ($start + $processed === $this->length) {
                $ints = array_map(static fn(int $v): int => intdiv($v + 11, 12), $counts);
                $min = min($ints);
                if ($ints[self::ASCII] === $min) {
                    return self::ASCII;
                }
                $minimums = array_keys($ints, $min, true);
                if (count($minimums) === 1) {
                    foreach ([self::BASE256, self::EDIFACT, self::TEXT, self::X12] as $mode) {
                        if ($ints[$mode] === $min) {
                            return $mode;
                        }
                    }
                }

                return self::C40;
            }

            $c = $this->message[$start + $processed];
            $processed++;
            $extended = $c >= 128;

            if ($this->isDigit($c)) {
                $counts[self::ASCII] += 6;
            } else {
                $counts[self::ASCII] = intdiv($counts[self::ASCII] + 11, 12) * 12 + ($extended ? 24 : 12);
            }

            $counts[self::C40] += $this->isNativeC40($c) ? 8 : ($extended ? 32 : 16);
            $counts[self::TEXT] += $this->isNativeText($c) ? 8 : ($extended ? 32 : 16);
            $counts[self::X12] += $this->isNativeX12($c) ? 8 : ($extended ? 52 : 40);
            $counts[self::EDIFACT] += $this->isNativeEdifact($c) ? 9 : ($extended ? 51 : 39);
            $counts[self::BASE256] += 12;

            if ($processed < 4) {
                continue;
            }

            [$a, $c40, $t, $x, $e, $b] = array_map(static fn(int $v): int => intdiv($v + 11, 12), $counts);

            if ($a < min($b, $c40, $t, $x, $e)) {
                return self::ASCII;
            }
            if ($b < $a || $b + 1 < min($c40, $t, $x, $e)) {
                return self::BASE256;
            }
            if ($e + 1 < min($b, $c40, $t, $x, $a)) {
                return self::EDIFACT;
            }
            if ($t + 1 < min($b, $c40, $e, $x, $a)) {
                return self::TEXT;
            }
            if ($x + 1 < min($b, $c40, $e, $t, $a)) {
                return self::X12;
            }
            if ($c40 + 1 < min($a, $b, $e, $t)) {
                if ($c40 < $x) {
                    return self::C40;
                }
                if ($c40 === $x) {
                    for ($p = $start + $processed + 1; $p < $this->length; $p++) {
                        $tc = $this->message[$p];
                        if ($tc === 13 || $tc === 42 || $tc === 62) {
                            return self::X12;
                        }
                        if (!$this->isNativeX12($tc)) {
                            break;
                        }
                    }

                    return self::C40;
                }
            }
        }
    }

    // --------------------------------------------------------------- helpers

    private function capacityFor(int $codewords): int
    {
        return ($this->capacity)($codewords) ?? $codewords;
    }

    private function allSingleAscii(int $from, int $to): bool
    {
        for ($i = $from; $i < $to; $i++) {
            if ($this->message[$i] >= 128) {
                return false;
            }
        }

        return true;
    }

    private function isFnc1(int $c): bool
    {
        return $this->gs1 && $c === self::GS;
    }

    private function isDigit(int $c): bool
    {
        return $c >= 48 && $c <= 57;
    }

    private function isNativeC40(int $c): bool
    {
        return $c === 32 || $this->isDigit($c) || ($c >= 65 && $c <= 90);
    }

    private function isNativeText(int $c): bool
    {
        return $c === 32 || $this->isDigit($c) || ($c >= 97 && $c <= 122);
    }

    private function isNativeX12(int $c): bool
    {
        return $c === 13 || $c === 42 || $c === 62 || $this->isNativeC40($c);
    }

    private function isNativeEdifact(int $c): bool
    {
        return $c >= 32 && $c <= 94;
    }
}
