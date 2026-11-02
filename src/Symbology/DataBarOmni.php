<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Symbol\LinearSymbol;
use PhpBarcode\Symbol\StackedLinearSymbol;
use PhpBarcode\Symbol\Symbol;

/**
 * GS1 DataBar Omnidirectional (formerly RSS-14), with its Truncated, Stacked
 * and Stacked Omnidirectional layouts.
 *
 * Encodes a GTIN-14: pass 13 digits, 14 digits with the check digit, or
 * "(01)" followed by the GTIN. The check digit is computed or verified.
 */
final class DataBarOmni extends AbstractDataBar
{
    /** Group boundaries (Gsum), outside groups 0-4 then inside groups 5-8. */
    private const G_SUM = [0, 161, 961, 2015, 2715, 0, 336, 1036, 1516];

    /** Teven for outside characters, Todd for inside characters. */
    private const T_EVEN_ODD = [1, 10, 34, 70, 126, 4, 20, 48, 81];

    private const MODULES_ODD = [12, 10, 8, 6, 4, 5, 7, 9, 11];
    private const MODULES_EVEN = [4, 6, 8, 10, 12, 10, 8, 6, 4];
    private const WIDEST_ODD = [8, 6, 4, 3, 1, 2, 4, 6, 8];

    private const FINDER_PATTERNS = [
        [3, 8, 2, 1, 1], [3, 5, 5, 1, 1], [3, 3, 7, 1, 1], [3, 1, 9, 1, 1], [2, 7, 4, 1, 1],
        [2, 5, 6, 1, 1], [2, 3, 8, 1, 1], [1, 5, 7, 1, 1], [1, 3, 9, 1, 1],
    ];

    private const CHECKSUM_WEIGHTS = [
        [1, 3, 9, 27, 2, 6, 18, 54],
        [4, 12, 36, 29, 8, 24, 72, 58],
        [16, 48, 65, 37, 32, 17, 51, 74],
        [64, 34, 23, 69, 49, 68, 46, 59],
    ];

    public function __construct(
        private readonly DataBarOmniLayout $layout = DataBarOmniLayout::Omnidirectional,
    ) {}

    public function name(): string
    {
        return match ($this->layout) {
            DataBarOmniLayout::Omnidirectional => 'GS1 DataBar Omnidirectional',
            DataBarOmniLayout::Truncated => 'GS1 DataBar Truncated',
            DataBarOmniLayout::Stacked => 'GS1 DataBar Stacked',
            DataBarOmniLayout::StackedOmnidirectional => 'GS1 DataBar Stacked Omnidirectional',
        };
    }

    protected function validate(string $data): void
    {
        $this->gtinWithoutCheckDigit($data);
    }

    protected function doEncode(string $data): Symbol
    {
        $gtin = $this->gtinWithoutCheckDigit($data);
        [$widths, $rightCheck] = $this->totalWidths((int) $gtin);
        $text = $this->gtinText($gtin);

        if ($this->layout === DataBarOmniLayout::Omnidirectional) {
            return new LinearSymbol(array_slice($widths, 1), $text, $this->name(), $this->quietZone());
        }

        if ($this->layout === DataBarOmniLayout::Truncated) {
            return new StackedLinearSymbol([['bits' => self::expand($widths, false), 'height' => 13]], $text, $this->name(), $this->quietZone());
        }

        $top = self::expand(array_slice($widths, 0, 23), false) . '10';
        $bottom = '10' . self::expand(array_slice($widths, 23), true);

        if ($this->layout === DataBarOmniLayout::Stacked) {
            return new StackedLinearSymbol([
                ['bits' => $top, 'height' => 5],
                ['bits' => self::stackedSeparator($top, $bottom), 'height' => 1],
                ['bits' => $bottom, 'height' => 7],
            ], $text, $this->name(), $this->quietZone());
        }

        $middle = '';
        for ($i = 0; $i < 46; $i++) {
            $middle .= $i >= 5 && $i % 2 === 1 ? '1' : '0';
        }

        return new StackedLinearSymbol([
            ['bits' => $top, 'height' => 33, 'flexible' => true],
            ['bits' => self::omniSeparator($top, 18, false), 'height' => 1],
            ['bits' => $middle, 'height' => 1],
            ['bits' => self::omniSeparator($bottom, 19, $rightCheck === 3), 'height' => 1],
            ['bits' => $bottom, 'height' => 33, 'flexible' => true],
        ], $text, $this->name(), $this->quietZone());
    }

    /**
     * The 46 element widths of the full symbol (starting with the left guard's
     * space), and the right check value (needed for one separator special case).
     *
     * @return array{list<int>, int}
     */
    private function totalWidths(int $value): array
    {
        $left = intdiv($value, 4537077);
        $right = $value % 4537077;
        $characters = [intdiv($left, 1597), $left % 1597, intdiv($right, 1597), $right % 1597];

        $data = [];
        foreach ($characters as $i => $character) {
            $outside = $i % 2 === 0;
            $group = self::group($character, $outside);
            $v = $character - self::G_SUM[$group];
            $quotient = intdiv($v, self::T_EVEN_ODD[$group]);
            $remainder = $v % self::T_EVEN_ODD[$group];
            $data[$i] = self::characterWidths(
                $outside ? $quotient : $remainder,
                $outside ? $remainder : $quotient,
                self::MODULES_ODD[$group],
                self::MODULES_EVEN[$group],
                4,
                self::WIDEST_ODD[$group],
                !$outside,
            );
        }

        $checksum = 0;
        foreach ($data as $i => $widths) {
            foreach ($widths as $j => $width) {
                $checksum += self::CHECKSUM_WEIGHTS[$i][$j] * $width;
            }
        }
        $checksum %= 79;
        if ($checksum >= 8) {
            $checksum++;
        }
        if ($checksum >= 72) {
            $checksum++;
        }
        $leftCheck = intdiv($checksum, 9);
        $rightCheck = $checksum % 9;

        $total = array_fill(0, 46, 1);
        for ($i = 0; $i < 8; $i++) {
            $total[$i + 2] = $data[0][$i];
            $total[$i + 15] = $data[1][7 - $i];
            $total[$i + 23] = $data[3][$i];
            $total[$i + 36] = $data[2][7 - $i];
        }
        for ($i = 0; $i < 5; $i++) {
            $total[$i + 10] = self::FINDER_PATTERNS[$leftCheck][$i];
            $total[$i + 31] = self::FINDER_PATTERNS[$rightCheck][4 - $i];
        }

        return [$total, $rightCheck];
    }

    private static function group(int $value, bool $outside): int
    {
        $end = $outside ? 4 : 8;
        for ($i = $outside ? 0 : 5; $i < $end; $i++) {
            if ($value < self::G_SUM[$i + 1]) {
                return $i;
            }
        }

        return $end;
    }

    /**
     * Separator between the two rows of DataBar Stacked (ISO/IEC 24724 5.3.2.1).
     */
    private static function stackedSeparator(string $top, string $bottom): string
    {
        $separator = '0';
        for ($i = 1; $i < 46; $i++) {
            if ($top[$i] === $bottom[$i]) {
                $separator .= $top[$i] === '0' ? '1' : '0';
            } else {
                $separator .= $separator[$i - 1] === '0' ? '1' : '0';
            }
        }

        return '0000' . substr($separator, 4);
    }

    /**
     * Separator adjacent to a row of DataBar Stacked Omnidirectional (ISO/IEC 24724 5.3.2.2):
     * the complement of the row, with alternating modules over the finder pattern.
     */
    private static function omniSeparator(string $row, int $finderStart, bool $finderValueThree): string
    {
        $separator = str_repeat('0', 46);
        for ($i = 4; $i < 46; $i++) {
            $separator[$i] = $row[$i] === '1' ? '0' : '1';
        }

        if ($finderValueThree) {
            // The single dark module over finder value 3 moves onto its 3-module bar.
            for ($i = $finderStart; $i < $finderStart + 13; $i++) {
                $separator[$i] = $i === $finderStart + 10 ? '1' : '0';
            }

            return $separator;
        }

        $latch = true;
        for ($i = $finderStart; $i < $finderStart + 13; $i++) {
            if ($row[$i] === '0') {
                $separator[$i] = $latch ? '1' : '0';
                $latch = !$latch;
            } else {
                $separator[$i] = '0';
                $latch = true;
            }
        }

        return $separator;
    }
}
