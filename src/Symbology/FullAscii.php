<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;

/**
 * Full ASCII expansion tables for Code 39 and Code 93 (ANSI/AIM BC1 and BC5).
 *
 * Each of the 128 ASCII characters maps to one or two characters of the base
 * symbology, using $ % / + as shift characters.
 *
 * @internal
 */
final class FullAscii
{
    /**
     * Code 39 Full ASCII sequence for one byte (0-127).
     */
    public static function code39(int $byte): string
    {
        return match (true) {
            $byte === 0 => '%U',
            $byte >= 1 && $byte <= 26 => '$' . chr(64 + $byte),
            $byte >= 27 && $byte <= 31 => '%' . chr(65 + $byte - 27),
            $byte === 32, $byte === 45, $byte === 46, $byte >= 48 && $byte <= 57, $byte >= 65 && $byte <= 90 => chr($byte),
            $byte >= 33 && $byte <= 44, $byte === 47 => '/' . chr(65 + $byte - 33),
            $byte === 58 => '/Z',
            $byte >= 59 && $byte <= 63 => '%' . chr(70 + $byte - 59),
            $byte === 64 => '%V',
            $byte >= 91 && $byte <= 95 => '%' . chr(75 + $byte - 91),
            $byte === 96 => '%W',
            $byte >= 97 && $byte <= 122 => '+' . chr($byte - 32),
            $byte >= 123 && $byte <= 127 => '%' . chr(80 + $byte - 123),
            default => throw new \InvalidArgumentException(sprintf('Byte %d is not ASCII.', $byte)),
        };
    }

    /**
     * Code 93 Full ASCII sequence, using the dedicated shift characters
     * "\x01" = ($), "\x02" = (%), "\x03" = (/), "\x04" = (+).
     */
    public static function code93(int $byte): string
    {
        // In Code 93 these characters exist natively instead of being shifted.
        if (in_array($byte, [36, 37, 43, 47], true)) {
            return chr($byte);
        }

        $sequence = self::code39($byte);

        return strlen($sequence) === 2 ? strtr($sequence[0], '$%/+', "\x01\x02\x03\x04") . $sequence[1] : $sequence;
    }

    /**
     * @param callable(int): string $map
     */
    public static function expand(string $data, string $symbology, callable $map): string
    {
        $expanded = '';
        foreach (str_split($data) as $position => $character) {
            $byte = ord($character);
            if ($byte > 127) {
                throw InvalidDataException::invalidCharacter($symbology, $character, $position);
            }
            $expanded .= $map($byte);
        }

        return $expanded;
    }

    /**
     * Replaces control characters for display.
     */
    public static function printable(string $data): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $data);
    }
}
