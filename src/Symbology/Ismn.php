<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Checksum\Mod10;
use PhpBarcode\Exception\InvalidDataException;

/**
 * ISMN (printed music) as an EAN-13 with prefix 979-0.
 *
 * Accepts the 13-digit form ("979-0-2306-7118-7") or the legacy 10-character
 * form ("M-2306-7118-7"). The legacy check digit equals the EAN-13 check
 * digit of the 979-0 form, so both are verified the same way.
 */
final class Ismn extends AbstractEanDerived
{
    public function name(): string
    {
        return 'ISMN';
    }

    protected function validate(string $data): void
    {
        [$ismn, $addOn] = $this->parts($data);
        $this->validateAddOn($addOn);

        $digits = self::toDigits($ismn);
        if ($digits === null) {
            throw new InvalidDataException(sprintf('"%s" is not a valid ISMN (979-0 followed by 9 digits, or M followed by 9 digits).', $ismn));
        }
        if (strlen($digits) === 13) {
            $expected = (new Mod10())->calculate(substr($digits, 0, 12));
            if ($expected !== $digits[12]) {
                throw InvalidDataException::invalidCheckDigit($this->name(), $expected, $digits[12]);
            }
        }
    }

    protected function toEan(string $identifier): string
    {
        $digits = (string) self::toDigits($identifier);

        return strlen($digits) === 13 ? $digits : $digits . (new Mod10())->calculate($digits);
    }

    protected function heading(string $identifier, string $ean): string
    {
        return 'ISMN ' . $ean;
    }

    private static function toDigits(string $ismn): ?string
    {
        return match (1) {
            preg_match('/^M(\d{8,9})$/', $ismn, $m) => '9790' . $m[1],
            preg_match('/^9790\d{8,9}$/', $ismn) => $ismn,
            default => null,
        };
    }
}
