<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Symbol\LinearSymbol;

/**
 * MSI Plessey (Modified Plessey), a numeric symbology used for shelf labels.
 *
 * Each digit is four BCD bits, a 0 bit being a narrow bar and wide space and
 * a 1 bit the reverse. Check digit schemes follow Zint (BSD-3-Clause): the
 * modulo 11 weights are 2-7 (IBM) by default or 2-9 (NCR), and a modulo 11
 * result of 10 is written as the two digits "10".
 */
final class MsiPlessey extends AbstractSymbology
{
    public function __construct(
        private readonly MsiChecksum $checksum = MsiChecksum::Mod10,
        private readonly bool $ncrWeights = false,
    ) {}

    public function name(): string
    {
        return 'MSI Plessey';
    }

    protected function validate(string $data): void
    {
        $this->assertAlphabet($data, '0123456789');
    }

    protected function doEncode(string $data): LinearSymbol
    {
        $payload = match ($this->checksum) {
            MsiChecksum::None => $data,
            MsiChecksum::Mod10 => $data . self::mod10($data),
            MsiChecksum::Mod1010 => ($first = $data . self::mod10($data)) . self::mod10($first),
            MsiChecksum::Mod11 => $data . $this->mod11($data),
            MsiChecksum::Mod1110 => ($first = $data . $this->mod11($data)) . self::mod10($first),
        };

        $pattern = '21';
        foreach (str_split($payload) as $digit) {
            $pattern .= strtr(str_pad(decbin((int) $digit), 4, '0', STR_PAD_LEFT), ['0' => '12', '1' => '21']);
        }

        return $this->fromWidths($pattern . '121', $payload);
    }

    /**
     * Luhn-style modulo 10: digits alternately doubled, starting with the rightmost.
     */
    public static function mod10(string $data): string
    {
        $sum = 0;
        foreach (str_split(strrev($data)) as $i => $digit) {
            $value = (int) $digit * ($i % 2 === 0 ? 2 : 1);
            $sum += intdiv($value, 10) + $value % 10;
        }

        return (string) ((10 - $sum % 10) % 10);
    }

    private function mod11(string $data): string
    {
        $wrap = $this->ncrWeights ? 9 : 7;
        $sum = 0;
        foreach (str_split(strrev($data)) as $i => $digit) {
            $sum += (int) $digit * ($i % ($wrap - 1) + 2);
        }

        return (string) ((11 - $sum % 11) % 11);
    }
}
