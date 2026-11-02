<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Checksum\Mod10;
use PhpBarcode\Exception\InvalidDataException;

/**
 * Shared machinery for the GS1 DataBar family (ISO/IEC 24724).
 *
 * The width-generation routine is the getRSSwidths() algorithm from ISO/IEC
 * 24724 Annex B; the tables and encoding structure follow the standard as
 * implemented in Zint (backend/rss.c, BSD-3-Clause, (c) Robin Stuart) and
 * verified against zxing-cpp.
 */
abstract class AbstractDataBar extends AbstractSymbology
{
    public function quietZone(): int
    {
        // DataBar needs no quiet zone; one module keeps the guard pattern clear of edges.
        return 1;
    }

    /**
     * Normalizes a GTIN input ("(01)" prefix optional, check digit optional)
     * to its first 13 digits, verifying the check digit if one is given.
     *
     * @throws InvalidDataException
     */
    protected function gtinWithoutCheckDigit(string $data): string
    {
        $digits = preg_replace('/^(?:\(01\)|\[01\])/', '', trim($data)) ?? '';
        if (strlen($digits) === 16 && str_starts_with($digits, '01')) {
            $digits = substr($digits, 2);
        }

        $this->assertAlphabet($digits, '0123456789');

        if (strlen($digits) === 14) {
            $expected = (new Mod10())->calculate(substr($digits, 0, 13));
            if ($digits[13] !== $expected) {
                throw InvalidDataException::invalidCheckDigit($this->name(), $expected, $digits[13]);
            }

            return substr($digits, 0, 13);
        }
        if (strlen($digits) !== 13) {
            throw InvalidDataException::invalidLength($this->name(), 'a 14-digit GTIN (or 13 digits without the check digit)', strlen($digits));
        }

        return $digits;
    }

    protected function gtinText(string $gtin13): string
    {
        return '(01)' . $gtin13 . (new Mod10())->calculate($gtin13);
    }

    /**
     * Element widths for value $val spread over $elements elements totalling $n
     * modules, none wider than $maxWidth (ISO/IEC 24724 Annex B, getRSSwidths).
     *
     * @return list<int>
     */
    protected static function elementWidths(int $val, int $n, int $elements, int $maxWidth, bool $noNarrow): array
    {
        $widths = [];
        $narrowMask = 0;
        for ($bar = 0; $bar < $elements - 1; $bar++) {
            $elmWidth = 1;
            $narrowMask |= 1 << $bar;
            while (true) {
                $subVal = self::combinations($n - $elmWidth - 1, $elements - $bar - 2);
                if ($noNarrow && $narrowMask === 0 && $n - $elmWidth - ($elements - $bar - 1) >= $elements - $bar - 1) {
                    $subVal -= self::combinations($n - $elmWidth - ($elements - $bar), $elements - $bar - 2);
                }
                if ($elements - $bar - 1 > 1) {
                    $lessVal = 0;
                    for ($mxwElement = $n - $elmWidth - ($elements - $bar - 2); $mxwElement > $maxWidth; $mxwElement--) {
                        $lessVal += self::combinations($n - $elmWidth - $mxwElement - 1, $elements - $bar - 3);
                    }
                    $subVal -= $lessVal * ($elements - 1 - $bar);
                } elseif ($n - $elmWidth > $maxWidth) {
                    $subVal--;
                }
                $val -= $subVal;
                if ($val < 0) {
                    break;
                }
                $elmWidth++;
                $narrowMask &= ~(1 << $bar);
            }
            $val += $subVal;
            $n -= $elmWidth;
            $widths[] = $elmWidth;
        }
        $widths[] = $n;

        return $widths;
    }

    /**
     * Interleaves the odd (bar) and even (space) element widths of one character.
     *
     * @return list<int>
     */
    protected static function characterWidths(
        int $oddValue,
        int $evenValue,
        int $oddModules,
        int $evenModules,
        int $elements,
        int $oddWidest,
        bool $oddNoNarrow,
    ): array {
        $odd = self::elementWidths($oddValue, $oddModules, $elements, $oddWidest, $oddNoNarrow);
        $even = self::elementWidths($evenValue, $evenModules, $elements, 9 - $oddWidest, !$oddNoNarrow);

        $widths = [];
        for ($i = 0; $i < $elements; $i++) {
            $widths[] = $odd[$i];
            $widths[] = $even[$i];
        }

        return $widths;
    }

    /**
     * Expands run-length widths into a module bitmap.
     *
     * @param list<int> $widths
     */
    protected static function expand(array $widths, bool $startWithBar): string
    {
        $bits = '';
        $bar = $startWithBar;
        foreach ($widths as $width) {
            $bits .= str_repeat($bar ? '1' : '0', $width);
            $bar = !$bar;
        }

        return $bits;
    }

    /**
     * n choose r, returning 1 for the out-of-range arguments the Annex B routine relies on.
     */
    private static function combinations(int $n, int $r): int
    {
        if ($r < 0 || $n < 0 || $n < $r) {
            return 1;
        }
        $r = min($r, $n - $r);
        $result = 1;
        for ($i = 1; $i <= $r; $i++) {
            $result = intdiv($result * ($n - $r + $i), $i);
        }

        return $result;
    }
}
