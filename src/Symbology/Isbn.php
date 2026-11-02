<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Checksum\Mod10;
use PhpBarcode\Exception\InvalidDataException;

/**
 * ISBN (International Standard Book Number) printed as an EAN-13 "Bookland" symbol.
 *
 * Accepts ISBN-13 (978/979 prefix) or ISBN-10 (check character 0-9 or X),
 * with or without hyphens and an optional "ISBN" prefix. A 5-digit price
 * add-on may follow: "978-1-4028-9462-6 51299".
 */
final class Isbn extends AbstractEanDerived
{
    public function name(): string
    {
        return 'ISBN';
    }

    protected function validate(string $data): void
    {
        [$isbn, $addOn] = $this->parts($data);
        $this->validateAddOn($addOn);

        if (strlen($isbn) === 10) {
            if (preg_match('/^\d{9}[\dX]$/', $isbn) !== 1) {
                throw new InvalidDataException(sprintf('"%s" is not a valid ISBN-10.', $isbn));
            }
            $expected = self::isbn10Check(substr($isbn, 0, 9));
            if ($expected !== $isbn[9]) {
                throw InvalidDataException::invalidCheckDigit('ISBN-10', $expected, $isbn[9]);
            }

            return;
        }

        $this->assertAlphabet($isbn, '0123456789');
        if (strlen($isbn) !== 13 && strlen($isbn) !== 12) {
            throw InvalidDataException::invalidLength($this->name(), '13 digits (ISBN-13) or 10 characters (ISBN-10)', strlen($isbn));
        }
        if (!str_starts_with($isbn, '978') && !str_starts_with($isbn, '979')) {
            throw new InvalidDataException('An ISBN-13 must start with 978 or 979.');
        }
        if (strlen($isbn) === 13) {
            $expected = (new Mod10())->calculate(substr($isbn, 0, 12));
            if ($expected !== $isbn[12]) {
                throw InvalidDataException::invalidCheckDigit($this->name(), $expected, $isbn[12]);
            }
        }
    }

    protected function toEan(string $identifier): string
    {
        if (strlen($identifier) === 10) {
            $identifier = '978' . substr($identifier, 0, 9);
        }

        return strlen($identifier) === 13 ? $identifier : $identifier . (new Mod10())->calculate($identifier);
    }

    protected function heading(string $identifier, string $ean): string
    {
        return 'ISBN ' . $ean;
    }

    /**
     * ISBN-10 check character: weights 10..2, modulo 11, 10 written as "X".
     */
    public static function isbn10Check(string $nineDigits): string
    {
        $sum = 0;
        foreach (str_split($nineDigits) as $i => $digit) {
            $sum += (10 - $i) * (int) $digit;
        }
        $check = (11 - $sum % 11) % 11;

        return $check === 10 ? 'X' : (string) $check;
    }
}
