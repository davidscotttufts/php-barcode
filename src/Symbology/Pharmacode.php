<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\LinearSymbol;

/**
 * Pharmacode (Laetus one-track), a binary code for packaging control.
 *
 * Encodes a single integer from 3 to 131070 as narrow (1 module) and wide
 * (3 modules) bars separated by 2-module spaces (Laetus Pharmacode Guide).
 */
final class Pharmacode extends AbstractSymbology
{
    public function name(): string
    {
        return 'Pharmacode';
    }

    public function quietZone(): int
    {
        return 6;
    }

    protected function normalize(string $data): string
    {
        return ltrim(trim($data), '0') ?: trim($data);
    }

    protected function validate(string $data): void
    {
        $this->assertAlphabet($data, '0123456789');

        if (strlen($data) > 6 || (int) $data < 3 || (int) $data > 131070) {
            throw new InvalidDataException(sprintf('Pharmacode values range from 3 to 131070, got "%s".', $data));
        }
    }

    protected function doEncode(string $data): LinearSymbol
    {
        $value = (int) $data;
        $bars = [];
        while ($value > 0) {
            if ($value % 2 === 0) {
                $bars[] = 3;
                $value = intdiv($value - 2, 2);
            } else {
                $bars[] = 1;
                $value = intdiv($value - 1, 2);
            }
        }

        $widths = [];
        foreach (array_reverse($bars) as $i => $bar) {
            if ($i > 0) {
                $widths[] = 2;
            }
            $widths[] = $bar;
        }

        return new LinearSymbol($widths, $data, $this->name(), $this->quietZone());
    }
}
