<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\LinearSymbol;

/**
 * PZN8 (Pharmazentralnummer), the German pharmaceutical product number.
 *
 * Seven digits (zero-filled) plus a modulo 11 check digit with weights 1-7,
 * drawn as Code 39 with a leading "-". Numbers whose check digit would be 10
 * are not issued and are rejected.
 */
final class Pzn extends AbstractSymbology
{
    public function __construct(
        private readonly int $wideRatio = 3,
    ) {}

    public function name(): string
    {
        return 'PZN';
    }

    protected function normalize(string $data): string
    {
        $data = trim((string) preg_replace('/^\s*PZN[\s:-]*/i', '', $data));

        return strlen($data) < 7 && ctype_digit($data) ? str_pad($data, 7, '0', STR_PAD_LEFT) : $data;
    }

    protected function validate(string $data): void
    {
        $this->assertAlphabet($data, '0123456789');

        if (strlen($data) !== 7 && strlen($data) !== 8) {
            throw InvalidDataException::invalidLength($this->name(), 'up to 7 digits (or 8 with the check digit)', strlen($data));
        }

        $check = self::check(substr($data, 0, 7));
        if ($check === null) {
            throw new InvalidDataException(sprintf('PZN %s is invalid: its check digit would be 10.', substr($data, 0, 7)));
        }
        if (strlen($data) === 8 && $check !== $data[7]) {
            throw InvalidDataException::invalidCheckDigit($this->name(), $check, $data[7]);
        }
    }

    protected function doEncode(string $data): LinearSymbol
    {
        $number = substr($data, 0, 7) . self::check(substr($data, 0, 7));

        $symbol = (new Code39(false, $this->wideRatio))->encode('-' . $number);
        \assert($symbol instanceof LinearSymbol);

        return $symbol->withText('PZN - ' . $number)->withSymbology($this->name());
    }

    public static function check(string $sevenDigits): ?string
    {
        $sum = 0;
        foreach (str_split($sevenDigits) as $i => $digit) {
            $sum += ($i + 1) * (int) $digit;
        }

        return $sum % 11 === 10 ? null : (string) ($sum % 11);
    }
}
