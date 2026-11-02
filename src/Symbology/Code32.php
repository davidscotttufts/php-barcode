<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\LinearSymbol;

/**
 * Code 32 (Italian Pharmacode, "Codice Farmaceutico"), for medicines sold in Italy.
 *
 * Takes the 8-digit AIC number (shorter input is zero-filled; a 9th digit is
 * treated as the check digit and verified), converts the 9-digit number to
 * six base-32 characters and draws them as Code 39. The human-readable text
 * is "A" followed by the nine digits. Algorithm per Zint (BSD-3-Clause).
 */
final class Code32 extends AbstractSymbology
{
    private const BASE32 = '0123456789BCDFGHJKLMNPQRSTUVWXYZ';

    public function __construct(
        private readonly int $wideRatio = 3,
    ) {}

    public function name(): string
    {
        return 'Code 32';
    }

    protected function normalize(string $data): string
    {
        $data = ltrim(trim($data), 'Aa');

        return strlen($data) < 8 && ctype_digit($data) ? str_pad($data, 8, '0', STR_PAD_LEFT) : $data;
    }

    protected function validate(string $data): void
    {
        $this->assertAlphabet($data, '0123456789');

        if (strlen($data) !== 8 && strlen($data) !== 9) {
            throw InvalidDataException::invalidLength($this->name(), 'up to 8 digits (or 9 with the check digit)', strlen($data));
        }
        if (strlen($data) === 9 && self::check(substr($data, 0, 8)) !== $data[8]) {
            throw InvalidDataException::invalidCheckDigit($this->name(), self::check(substr($data, 0, 8)), $data[8]);
        }
    }

    protected function doEncode(string $data): LinearSymbol
    {
        $number = substr($data, 0, 8) . self::check(substr($data, 0, 8));

        $value = (int) $number;
        $encoded = '';
        for ($i = 0; $i < 6; $i++) {
            $encoded = self::BASE32[$value % 32] . $encoded;
            $value = intdiv($value, 32);
        }

        $symbol = (new Code39(false, $this->wideRatio))->encode($encoded);
        \assert($symbol instanceof LinearSymbol);

        return $symbol->withText('A' . $number)->withSymbology($this->name());
    }

    /**
     * Digits in even positions are doubled (digit sum), odd ones added; result modulo 10.
     */
    public static function check(string $eightDigits): string
    {
        $sum = 0;
        foreach (str_split($eightDigits) as $i => $digit) {
            $value = (int) $digit * ($i % 2 === 1 ? 2 : 1);
            $sum += intdiv($value, 10) + $value % 10;
        }

        return (string) ($sum % 10);
    }
}
