<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Symbol\LinearSymbol;

/**
 * Plessey (UK), the original hexadecimal Plessey code with a CRC-8 check.
 *
 * Each hex digit is four bits, least significant first: a 0 bit is a narrow
 * bar and wide space, a 1 bit a wide bar and narrow space. Two check
 * characters are derived from a CRC with polynomial x⁸+x⁷+x⁶+x⁵+x³+1.
 * Start/stop patterns and CRC per Zint (BSD-3-Clause), after GNU Barcode.
 */
final class Plessey extends AbstractSymbology
{
    private const POLYNOMIAL = [1, 1, 1, 1, 0, 1, 0, 0, 1];
    private const START = '31311331';
    private const STOP = '331311313';

    public function name(): string
    {
        return 'Plessey';
    }

    protected function normalize(string $data): string
    {
        return strtoupper($data);
    }

    protected function validate(string $data): void
    {
        $this->assertAlphabet($data, '0123456789ABCDEF');
    }

    protected function doEncode(string $data): LinearSymbol
    {
        $bits = [];
        foreach (str_split($data) as $hex) {
            $value = (int) hexdec($hex);
            for ($b = 0; $b < 4; $b++) {
                $bits[] = ($value >> $b) & 1;
            }
        }

        $crc = [...$bits, 0, 0, 0, 0, 0, 0, 0, 0];
        for ($i = 0, $n = count($bits); $i < $n; $i++) {
            if ($crc[$i] === 1) {
                foreach (self::POLYNOMIAL as $j => $p) {
                    $crc[$i + $j] ^= $p;
                }
            }
        }

        $pattern = self::START;
        foreach ([...$bits, ...array_slice($crc, count($bits))] as $bit) {
            $pattern .= $bit === 1 ? '31' : '13';
        }

        return $this->fromWidths($pattern . self::STOP, $data);
    }
}
