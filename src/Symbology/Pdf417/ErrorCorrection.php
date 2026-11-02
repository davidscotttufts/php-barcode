<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\Pdf417;

/**
 * Reed–Solomon error correction over the prime field GF(929), as used by
 * PDF417 and MicroPDF417 (generator polynomial ∏ (x − 3^i) for i = 1…k).
 *
 * @internal
 */
final class ErrorCorrection
{
    private const PRIME = 929;

    /** @var array<int, list<int>> Generator coefficients by degree, lowest order first, without the leading 1. */
    private static array $generators = [];

    /**
     * Number of error-correction codewords for a PDF417 security level (0-8).
     */
    public static function codewordsForLevel(int $level): int
    {
        if ($level < 0 || $level > 8) {
            throw new \InvalidArgumentException('PDF417 error-correction levels range from 0 to 8.');
        }

        return 2 << $level;
    }

    /**
     * Computes $count error-correction codewords for $data, in transmission order.
     *
     * @param list<int> $data
     *
     * @return list<int>
     */
    public static function encode(array $data, int $count): array
    {
        $coefficients = self::generator($count);
        $ec = array_fill(0, $count, 0);

        foreach ($data as $codeword) {
            $feedback = ($codeword + $ec[$count - 1]) % self::PRIME;
            for ($j = $count - 1; $j > 0; $j--) {
                $ec[$j] = ($ec[$j - 1] + self::PRIME - ($feedback * $coefficients[$j]) % self::PRIME) % self::PRIME;
            }
            $ec[0] = (self::PRIME - ($feedback * $coefficients[0]) % self::PRIME) % self::PRIME;
        }

        $result = [];
        for ($j = $count - 1; $j >= 0; $j--) {
            $result[] = $ec[$j] === 0 ? 0 : self::PRIME - $ec[$j];
        }

        return $result;
    }

    /**
     * Coefficients of ∏ (x − 3^i), i = 1…$degree, lowest order first, excluding the leading x^degree term.
     *
     * @return list<int>
     */
    public static function generator(int $degree): array
    {
        if (isset(self::$generators[$degree])) {
            return self::$generators[$degree];
        }

        $poly = [1]; // lowest order first
        $root = 1;
        for ($i = 1; $i <= $degree; $i++) {
            $root = ($root * 3) % self::PRIME;
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $power => $coefficient) {
                $next[$power + 1] = ($next[$power + 1] + $coefficient) % self::PRIME;
                $next[$power] = ($next[$power] + self::PRIME - ($coefficient * $root) % self::PRIME) % self::PRIME;
            }
            $poly = $next;
        }

        // Drop the leading x^degree coefficient (always 1).
        return self::$generators[$degree] = array_slice($poly, 0, $degree);
    }
}
