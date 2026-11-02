<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\Aztec;

use PhpBarcode\Encoding\BitBuffer;

/**
 * Converts input bytes into the shortest Aztec bit stream.
 *
 * It explores every combination of mode latches, shifts, two-character
 * punctuation codes and binary shifts at once, keeping only candidates that
 * no other candidate dominates, and so finds the optimal encoding.
 *
 * Algorithm after the ZXing Aztec HighLevelEncoder (Apache License 2.0),
 * extended with FNC1 for GS1 data.
 *
 * @internal
 */
final class HighLevelEncoder
{
    public const MODE_UPPER = 0;
    public const MODE_LOWER = 1;
    public const MODE_DIGIT = 2;
    public const MODE_MIXED = 3;
    public const MODE_PUNCT = 4;

    /** Marker in the input for FNC1 (encoded as FLG(0)). */
    public const FNC1 = -1;

    /**
     * Latch codes between modes: (bit count << 16) | code value.
     */
    public const LATCH = [
        self::MODE_UPPER => [0, (5 << 16) + 28, (5 << 16) + 30, (5 << 16) + 29, (10 << 16) + (29 << 5) + 30],
        self::MODE_LOWER => [(9 << 16) + (30 << 4) + 14, 0, (5 << 16) + 30, (5 << 16) + 29, (10 << 16) + (29 << 5) + 30],
        self::MODE_DIGIT => [(4 << 16) + 14, (9 << 16) + (14 << 5) + 28, 0, (9 << 16) + (14 << 5) + 29, (14 << 16) + (14 << 10) + (29 << 5) + 30],
        self::MODE_MIXED => [(5 << 16) + 29, (5 << 16) + 28, (10 << 16) + (29 << 5) + 30, 0, (5 << 16) + 30],
        self::MODE_PUNCT => [(5 << 16) + 31, (10 << 16) + (31 << 5) + 28, (10 << 16) + (31 << 5) + 30, (10 << 16) + (31 << 5) + 29, 0],
    ];

    /**
     * Shift codes between modes; -1 where no shift exists.
     */
    public const SHIFT = [
        self::MODE_UPPER => [-1, -1, -1, -1, 0],
        self::MODE_LOWER => [28, -1, -1, -1, 0],
        self::MODE_DIGIT => [15, -1, -1, -1, 0],
        self::MODE_MIXED => [-1, -1, -1, -1, 0],
        self::MODE_PUNCT => [-1, -1, -1, -1, -1],
    ];

    /** Mixed-mode table, by code: index 0 (P/S) is not a character. */
    private const MIXED = [0, 32, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 27, 28, 29, 30, 31, 64, 92, 94, 95, 96, 124, 126, 127];

    /** Punctuation table, by code: 0 is FLG(n), 2-5 are the two-character pairs. */
    private const PUNCT = [0, 13, 0, 0, 0, 0, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 58, 59, 60, 61, 62, 63, 91, 93, 123, 125];

    /** @var array<int, array<int, int>>|null  [mode][byte] => code (> 0), or absent */
    private static ?array $charMap = null;

    /**
     * Splits a string into its byte values.
     *
     * @return list<int>
     */
    public static function bytes(string $data): array
    {
        return $data === '' ? [] : array_map(ord(...), str_split($data));
    }

    /**
     * @param list<int> $input Bytes 0-255, or self::FNC1.
     * @param int|null  $eci   ECI designator to emit first, if any.
     */
    public function encode(array $input, ?int $eci = null): BitBuffer
    {
        $states = [$eci === null ? State::initial() : State::initial()->appendFlg($eci)];

        for ($index = 0, $length = count($input); $index < $length; $index++) {
            $char = $input[$index];

            if ($char === self::FNC1) {
                $states = self::simplify(array_map(
                    static fn(State $state): State => $state->endBinaryShift($index)->appendFlg(null),
                    $states,
                ));
                continue;
            }

            $next = $input[$index + 1] ?? 0;
            $pairCode = match ($char) {
                13 => $next === 10 ? 2 : 0,
                46 => $next === 32 ? 3 : 0,
                44 => $next === 32 ? 4 : 0,
                58 => $next === 32 ? 5 : 0,
                default => 0,
            };

            if ($pairCode > 0) {
                $states = $this->updateForPair($states, $index, $pairCode);
                $index++;
            } else {
                $states = $this->updateForChar($states, $index, $char);
            }
        }

        $best = $states[0];
        foreach ($states as $state) {
            if ($state->bitCount < $best->bitCount) {
                $best = $state;
            }
        }

        return $best->toBits($input);
    }

    /**
     * @param list<State> $states
     *
     * @return list<State>
     */
    private function updateForChar(array $states, int $index, int $char): array
    {
        $map = self::charMap();
        $result = [];

        foreach ($states as $state) {
            $inCurrent = isset($map[$state->mode][$char]);
            $noBinary = null;

            for ($mode = self::MODE_UPPER; $mode <= self::MODE_PUNCT; $mode++) {
                $code = $map[$mode][$char] ?? 0;
                if ($code === 0) {
                    continue;
                }
                $noBinary ??= $state->endBinaryShift($index);

                // Latching only helps if the character isn't in the current mode (or to Digit, which is 4 bits).
                if (!$inCurrent || $mode === $state->mode || $mode === self::MODE_DIGIT) {
                    $result[] = $noBinary->latchAndAppend($mode, $code);
                }
                // Shifting only helps if the character isn't in the current mode.
                if (!$inCurrent && self::SHIFT[$state->mode][$mode] >= 0) {
                    $result[] = $noBinary->shiftAndAppend($mode, $code);
                }
            }

            if ($state->binaryShiftByteCount > 0 || !$inCurrent) {
                $result[] = $state->addBinaryShiftChar($index);
            }
        }

        return self::simplify($result);
    }

    /**
     * @param list<State> $states
     *
     * @return list<State>
     */
    private function updateForPair(array $states, int $index, int $pairCode): array
    {
        $result = [];

        foreach ($states as $state) {
            $noBinary = $state->endBinaryShift($index);
            $result[] = $noBinary->latchAndAppend(self::MODE_PUNCT, $pairCode);

            if ($state->mode !== self::MODE_PUNCT) {
                $result[] = $noBinary->shiftAndAppend(self::MODE_PUNCT, $pairCode);
            }

            // ". " and ", " are also available as two Digit-mode characters.
            if ($pairCode === 3 || $pairCode === 4) {
                $result[] = $noBinary->latchAndAppend(self::MODE_DIGIT, 16 - $pairCode)->latchAndAppend(self::MODE_DIGIT, 1);
            }

            if ($state->binaryShiftByteCount > 0) {
                $result[] = $state->addBinaryShiftChar($index)->addBinaryShiftChar($index + 1);
            }
        }

        return self::simplify($result);
    }

    /**
     * Drops every state that another state dominates.
     *
     * @param list<State> $states
     *
     * @return list<State>
     */
    private static function simplify(array $states): array
    {
        $kept = [];

        foreach ($states as $candidate) {
            foreach ($kept as $key => $existing) {
                if ($existing->isBetterThanOrEqualTo($candidate)) {
                    continue 2;
                }
                if ($candidate->isBetterThanOrEqualTo($existing)) {
                    unset($kept[$key]);
                }
            }
            $kept[] = $candidate;
        }

        return array_values($kept);
    }

    /**
     * @return array<int, array<int, int>>
     */
    private static function charMap(): array
    {
        if (self::$charMap !== null) {
            return self::$charMap;
        }

        $map = [self::MODE_UPPER => [32 => 1], self::MODE_LOWER => [32 => 1], self::MODE_DIGIT => [32 => 1, 44 => 12, 46 => 13], self::MODE_MIXED => [], self::MODE_PUNCT => []];

        for ($i = 0; $i < 26; $i++) {
            $map[self::MODE_UPPER][65 + $i] = $i + 2;
            $map[self::MODE_LOWER][97 + $i] = $i + 2;
        }
        for ($i = 0; $i < 10; $i++) {
            $map[self::MODE_DIGIT][48 + $i] = $i + 2;
        }
        foreach (self::MIXED as $code => $char) {
            if ($code > 0) {
                $map[self::MODE_MIXED][$char] = $code;
            }
        }
        foreach (self::PUNCT as $code => $char) {
            if ($char > 0) {
                $map[self::MODE_PUNCT][$char] = $code;
            }
        }

        return self::$charMap = $map;
    }
}
