<?php

declare(strict_types=1);

namespace PhpBarcode\Checksum;

/**
 * GS1 modulo-10 check digit, used by EAN, UPC and Interleaved 2 of 5.
 *
 * Weights alternate 3, 1, 3, … starting from the rightmost payload digit.
 */
final class Mod10 implements ChecksumInterface
{
    public function calculate(string $payload): string
    {
        if (!ctype_digit($payload)) {
            throw new \InvalidArgumentException('Mod10 checksums are only defined for digits.');
        }

        $sum = 0;
        foreach (str_split(strrev($payload)) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return (string) ((10 - $sum % 10) % 10);
    }
}
