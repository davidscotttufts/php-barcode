<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

/**
 * The three Code 128 code sets.
 */
enum Code128Subset: string
{
    /** Upper-case ASCII, digits, punctuation and control characters (0x00-0x5F). */
    case A = 'A';

    /** Upper- and lower-case printable ASCII (0x20-0x7F). */
    case B = 'B';

    /** Pairs of digits 00-99, packed two per symbol. */
    case C = 'C';

    public function startValue(): int
    {
        return match ($this) {
            self::A => 103,
            self::B => 104,
            self::C => 105,
        };
    }

    /**
     * The symbol value that switches *to* this code set from another one.
     */
    public function switchValue(): int
    {
        return match ($this) {
            self::A => 101,
            self::B => 100,
            self::C => 99,
        };
    }

    public function canEncode(int $byte): bool
    {
        return match ($this) {
            self::A => $byte >= 0 && $byte <= 95,
            self::B => $byte >= 32 && $byte <= 127,
            self::C => false, // Code C encodes digit pairs, see Code128::encodeDigitPair().
        };
    }

    public function valueOf(int $byte): int
    {
        return match ($this) {
            self::A => $byte < 32 ? $byte + 64 : $byte - 32,
            self::B => $byte - 32,
            self::C => throw new \LogicException('Code set C encodes digit pairs, not single bytes.'),
        };
    }
}
