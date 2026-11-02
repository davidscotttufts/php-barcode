<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\MaxiCode;

/**
 * Minimal-length MaxiCode codeword encoding across Code Sets A-E, with
 * Numeric Shift for runs of nine digits.
 *
 * Shortest-path search with backtracking by Bue Jensen (BWIPP, MIT License),
 * as implemented in Zint's maxicode.c (BSD 3-Clause; see Tables for the notice).
 *
 * @internal
 */
final class TextEncoder
{
    private const A = 0;
    private const B = 1;
    private const E = 2;
    private const C = 3;
    private const D = 4;
    private const STATES = 5;

    /** Latch codewords to reach [later state] from [prior state]. */
    private const LATCH_SEQ = [
        self::A => [[], [63], [58], [58], [58]],
        self::B => [[63], [], [63], [63], [63]],
        self::E => [[62, 62], [62, 62], [], [62, 62], [62, 62]],
        self::C => [[60, 60], [60, 60], [60, 60], [], [60, 60]],
        self::D => [[61, 61], [61, 61], [61, 61], [61, 61], []],
    ];

    private const SET_A = 0x01;
    private const SET_B = 0x02;
    private const SET_E = 0x04;
    private const SET_C = 0x08;
    private const SET_D = 0x10;

    private const OP_DIGITS = 0;
    private const OP_SHIFT_A = 0x20 | self::SET_A;
    private const OP_2SHIFT_A = 0x40 | self::SET_A;
    private const OP_3SHIFT_A = 0x80 | self::SET_A;
    private const OP_SHIFT_B = 0x20 | self::SET_B;
    private const OP_SHIFT_E = 0x20 | self::SET_E;
    private const OP_SHIFT_C = 0x20 | self::SET_C;
    private const OP_SHIFT_D = 0x20 | self::SET_D;

    /** [op, characters consumed, extra shift codeword] */
    private const OPS = [
        0 => [self::OP_DIGITS, 9, 0],
        1 => [self::SET_A, 1, 0],
        2 => [self::SET_B, 1, 0],
        3 => [self::SET_E, 1, 0],
        4 => [self::SET_C, 1, 0],
        5 => [self::SET_D, 1, 0],
        6 => [self::OP_SHIFT_A, 1, 1],
        7 => [self::OP_2SHIFT_A, 2, 1],
        8 => [self::OP_3SHIFT_A, 3, 1],
        9 => [self::OP_SHIFT_B, 1, 1],
        10 => [self::OP_SHIFT_E, 1, 1],
        11 => [self::OP_SHIFT_C, 1, 1],
        12 => [self::OP_SHIFT_D, 1, 1],
    ];

    /** Operations available in each state (digits are handled separately). */
    private const STATE_OPS = [
        self::A => [1, 9, 10, 11, 12],
        self::B => [2, 6, 7, 8, 10, 11, 12],
        self::E => [3, 11, 12],
        self::C => [4, 10, 12],
        self::D => [5, 10, 11],
    ];

    /**
     * Encodes $data into codewords $start .. $end-1 of $codewords (padding the rest).
     *
     * @param array<int, int> $codewords
     * @param list<int>       $prefix    Codewords to place before the text (structured append, ECI).
     *
     * @throws \OverflowException when the data does not fit
     */
    public function encode(array &$codewords, int $start, int $end, string $data, array $prefix = []): void
    {
        $length = strlen($data);
        $bestLengths = array_fill(0, 16, array_fill(0, self::STATES, 0));
        $bestOrigins = array_fill(0, 16, array_fill(0, self::STATES, self::A));
        $pathOps = [];
        $priorSets = [];
        $digits = 0;
        $numA = 0;
        $row = 15;

        for ($i = 0; $i < $length; $i++) {
            $char = ord($data[$i]);
            $digits = ($char >= 48 && $char <= 57) ? $digits + 1 : 0;
            $numA = (Tables::CODE_SET[$char] & self::SET_A) !== 0 ? $numA + 1 : 0;
            $row = $i & 0x0F;

            for ($state = 0; $state < self::STATES; $state++) {
                $bestLengths[$row][$state] = $this->bestLength($state, $i, $char, $digits, $numA, $bestLengths, $bestOrigins, $pathOps, $priorSets);
            }
            for ($state = 0; $state < self::STATES; $state++) {
                $bestOrigins[$row][$state] = self::bestOrigin($state, $bestLengths[$row]);
            }
        }

        $minState = self::A;
        $minLength = PHP_INT_MAX;
        for ($state = 0; $state < self::STATES; $state++) {
            if ($bestLengths[$row][$state] < $minLength) {
                $minLength = $bestLengths[$row][$state];
                $minState = $state;
            }
        }

        $top = $start + count($prefix) + $minLength;
        if ($top > $end) {
            throw new \OverflowException(sprintf('The data needs %d codewords; only %d are available.', $top - $start, $end - $start));
        }

        // Walk the best path backwards from the end of the message.
        $ci = $top;
        $state = $minState;
        for ($i = $length; $i > 0;) {
            $prior = $priorSets[$i - 1][$state];
            [$op, $intake] = self::OPS[$pathOps[$i - 1][$state]];
            $i -= $intake;
            $ci = $this->emit($codewords, $ci, $data, $i, $op);

            if ($state !== $prior) {
                foreach (self::LATCH_SEQ[$state][$prior] as $latch) {
                    $codewords[--$ci] = $latch;
                }
                $state = $prior;
            }
        }

        foreach ($prefix as $offset => $codeword) {
            $codewords[$start + $offset] = $codeword;
        }

        $position = $top;
        if ($position < $end && ($minState === self::C || $minState === self::D)) {
            $codewords[$position++] = 58; // Latch A, so the padding is valid
        }
        for (; $position < $end; $position++) {
            $codewords[$position] = $minState === self::E ? 28 : 33; // PAD
        }
    }

    /**
     * @param array<int, array<int, int>> $bestLengths
     * @param array<int, array<int, int>> $bestOrigins
     * @param array<int, array<int, int>> $pathOps
     * @param array<int, array<int, int>> $priorSets
     */
    private function bestLength(int $state, int $i, int $char, int $digits, int $numA, array $bestLengths, array $bestOrigins, array &$pathOps, array &$priorSets): int
    {
        $min = PHP_INT_MAX;

        if ($digits >= 9) {
            // Nothing beats nine digits in six codewords.
            $m = ($i - 9) & 0x0F;
            $origin = $bestOrigins[$m][$state];
            $min = $bestLengths[$m][$origin] + count(self::LATCH_SEQ[$state][$origin]) + 6;
            $pathOps[$i][$state] = 0;
            $priorSets[$i][$state] = $origin;

            return $min;
        }

        foreach (self::STATE_OPS[$state] as $index) {
            [$op, $intake, $shift] = self::OPS[$index];
            if (!self::can($op, $char, $numA)) {
                continue;
            }
            $m = ($i - $intake) & 0x0F;
            $origin = $bestOrigins[$m][$state];
            $length = $bestLengths[$m][$origin] + count(self::LATCH_SEQ[$state][$origin]) + $intake + $shift;
            if ($length < $min) {
                $min = $length;
                $pathOps[$i][$state] = $index;
                $priorSets[$i][$state] = $origin;
            }
        }

        return $min;
    }

    /**
     * @param array<int, int> $lengths
     */
    private static function bestOrigin(int $state, array $lengths): int
    {
        $best = 0;
        $bestLength = $lengths[0] + count(self::LATCH_SEQ[$state][0]);
        for ($origin = 1; $origin < self::STATES; $origin++) {
            $length = $lengths[$origin] + count(self::LATCH_SEQ[$state][$origin]);
            if ($length < $bestLength) {
                $best = $origin;
                $bestLength = $length;
            }
        }

        return $best;
    }

    private static function can(int $op, int $char, int $numA): bool
    {
        if ($op === self::OP_2SHIFT_A || $op === self::OP_3SHIFT_A) {
            return $numA >= ($op === self::OP_3SHIFT_A ? 3 : 2);
        }

        return (Tables::CODE_SET[$char] & $op) !== 0;
    }

    /**
     * The value of $char in the Code Set used by $op.
     *
     * @param int<0, 255> $char
     */
    public static function symbolValue(int $op, int $char): int
    {
        $sets = Tables::CODE_SET[$char];

        if ($sets === ($op & 0x1F) || ($op & self::SET_A) !== 0) {
            return Tables::SYMBOL_CHAR[$char];
        }
        if (($op & self::SET_B) !== 0) {
            $position = strpos(' ,./:', chr($char));
            if ($position !== false) {
                return 47 + $position;
            }
        }
        if (($op & self::SET_E) !== 0 && $char >= 28 && $char <= 30) {
            return $char + 4; // FS GS RS
        }

        return $char === 32 ? 59 : $char;
    }

    /**
     * Writes the codewords for one operation, backwards, ending before $ci.
     *
     * @param array<int, int> $codewords
     */
    private function emit(array &$codewords, int $ci, string $data, int $i, int $op): int
    {
        if ($op === self::OP_DIGITS) {
            $value = (int) substr($data, $i, 9);
            for ($shift = 0; $shift <= 24; $shift += 6) {
                $codewords[--$ci] = ($value >> $shift) & 0x3F;
            }
            $codewords[--$ci] = 31; // NS

            return $ci;
        }

        if ($op === self::OP_2SHIFT_A || $op === self::OP_3SHIFT_A) {
            $count = $op === self::OP_3SHIFT_A ? 3 : 2;
            for ($k = $count - 1; $k >= 0; $k--) {
                $codewords[--$ci] = self::symbolValue($op, ord($data[$i + $k]));
            }
            $codewords[--$ci] = $count === 3 ? 57 : 56;

            return $ci;
        }

        $codewords[--$ci] = self::symbolValue($op, ord($data[$i]));

        if (($op & 0x20) !== 0) {
            $codewords[--$ci] = match ($op) {
                self::OP_SHIFT_C => 60,
                self::OP_SHIFT_D => 61,
                self::OP_SHIFT_E => 62,
                default => 59, // Shift A from B, Shift B from A
            };
        }

        return $ci;
    }
}
