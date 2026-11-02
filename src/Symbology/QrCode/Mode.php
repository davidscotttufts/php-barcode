<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\QrCode;

/**
 * QR Code data encoding modes.
 */
enum Mode
{
    case Numeric;
    case Alphanumeric;
    case Byte;
    case Kanji;

    public const ALPHANUMERIC_CHARSET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    /**
     * Four-bit QR mode indicator.
     */
    public function indicator(): int
    {
        return match ($this) {
            self::Numeric => 0b0001,
            self::Alphanumeric => 0b0010,
            self::Byte => 0b0100,
            self::Kanji => 0b1000,
        };
    }

    /**
     * Micro QR mode indicator value (its width depends on the symbol version).
     */
    public function microIndicator(): int
    {
        return match ($this) {
            self::Numeric => 0,
            self::Alphanumeric => 1,
            self::Byte => 2,
            self::Kanji => 3,
        };
    }

    /**
     * Width of the character count field for a QR Code version (1-40).
     */
    public function characterCountBits(int $version): int
    {
        $group = $version <= 9 ? 0 : ($version <= 26 ? 1 : 2);

        return match ($this) {
            self::Numeric => [10, 12, 14][$group],
            self::Alphanumeric => [9, 11, 13][$group],
            self::Byte => [8, 16, 16][$group],
            self::Kanji => [8, 10, 12][$group],
        };
    }

    /**
     * Width of the character count field for a Micro QR version (1-4), or null if unsupported.
     */
    public function microCharacterCountBits(int $version): ?int
    {
        return match ($this) {
            self::Numeric => [3, 4, 5, 6][$version - 1],
            self::Alphanumeric => [null, 3, 4, 5][$version - 1],
            self::Byte => [null, null, 4, 5][$version - 1],
            self::Kanji => [null, null, 3, 4][$version - 1],
        };
    }

    /**
     * Number of data bits needed for $count characters in this mode.
     */
    public function dataBits(int $count): int
    {
        return match ($this) {
            self::Numeric => intdiv($count, 3) * 10 + [0, 4, 7][$count % 3],
            self::Alphanumeric => intdiv($count, 2) * 11 + ($count % 2) * 6,
            self::Byte => $count * 8,
            self::Kanji => $count * 13,
        };
    }
}
