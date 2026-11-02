<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Checksum\Mod10;
use PhpBarcode\Exception\InvalidDataException;

/**
 * ISSN (serials) printed as an EAN-13 with prefix 977.
 *
 * Accepts an 8-character ISSN ("0317-8471", check character may be X) or 7
 * digits without check. The two-digit variant (price/issue code) defaults to
 * "00". An EAN-2 issue-number add-on may follow: "0317-8471 05".
 */
final class Issn extends AbstractEanDerived
{
    public function __construct(
        private readonly string $variant = '00',
        Ean13 $ean13 = new Ean13(),
    ) {
        if (preg_match('/^\d{2}$/', $variant) !== 1) {
            throw new \InvalidArgumentException('The ISSN variant must be two digits.');
        }
        parent::__construct($ean13);
    }

    public function name(): string
    {
        return 'ISSN';
    }

    protected function validate(string $data): void
    {
        [$issn, $addOn] = $this->parts($data);
        $this->validateAddOn($addOn);

        if (preg_match('/^\d{7}[\dX]?$/', $issn) !== 1) {
            throw new InvalidDataException(sprintf('"%s" is not a valid ISSN (7 digits plus a check character).', $issn));
        }
        if (strlen($issn) === 8) {
            $expected = self::check(substr($issn, 0, 7));
            if ($expected !== $issn[7]) {
                throw InvalidDataException::invalidCheckDigit($this->name(), $expected, $issn[7]);
            }
        }
    }

    protected function toEan(string $identifier): string
    {
        $payload = '977' . substr($identifier, 0, 7) . $this->variant;

        return $payload . (new Mod10())->calculate($payload);
    }

    protected function heading(string $identifier, string $ean): string
    {
        $issn = substr($identifier, 0, 7) . self::check(substr($identifier, 0, 7));

        return 'ISSN ' . substr($issn, 0, 4) . '-' . substr($issn, 4);
    }

    /**
     * ISSN check character: weights 8..2, modulo 11, 10 written as "X".
     */
    public static function check(string $sevenDigits): string
    {
        $sum = 0;
        foreach (str_split($sevenDigits) as $i => $digit) {
            $sum += (8 - $i) * (int) $digit;
        }
        $check = (11 - $sum % 11) % 11;

        return $check === 10 ? 'X' : (string) $check;
    }
}
