<?php

declare(strict_types=1);

namespace PhpBarcode\Checksum;

/**
 * Optional modulo-43 check character for Code 39.
 */
final class Mod43 implements ChecksumInterface
{
    public const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%';

    public function calculate(string $payload): string
    {
        $sum = 0;
        foreach (str_split($payload) as $character) {
            $value = strpos(self::ALPHABET, $character);
            if ($value === false) {
                throw new \InvalidArgumentException(sprintf('"%s" is not a Code 39 character.', $character));
            }
            $sum += $value;
        }

        return self::ALPHABET[$sum % 43];
    }
}
