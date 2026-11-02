<?php

declare(strict_types=1);

namespace PhpBarcode\Exception;

/**
 * Thrown when the data cannot be represented by the chosen symbology.
 */
final class InvalidDataException extends \InvalidArgumentException implements BarcodeException
{
    public static function empty(string $symbology): self
    {
        return new self(sprintf('%s cannot encode an empty string.', $symbology));
    }

    public static function invalidCharacter(string $symbology, string $character, int $position): self
    {
        $byte = ord($character[0] ?? "\0");
        $printable = match (true) {
            $byte >= 0x20 && $byte <= 0x7E => sprintf('character "%s"', $character),
            $byte >= 0x80 => sprintf('byte 0x%02X (non-ASCII text; this symbology only supports ASCII)', $byte),
            default => sprintf('control character 0x%02X', $byte),
        };

        return new self(sprintf(
            '%s cannot encode %s at position %d.',
            $symbology,
            $printable,
            $position,
        ));
    }

    public static function invalidLength(string $symbology, string $expected, int $actual): self
    {
        return new self(sprintf('%s requires %s, got %d.', $symbology, $expected, $actual));
    }

    public static function invalidCheckDigit(string $symbology, string $expected, string $actual): self
    {
        return new self(sprintf(
            '%s check digit is invalid: expected "%s", got "%s".',
            $symbology,
            $expected,
            $actual,
        ));
    }
}
