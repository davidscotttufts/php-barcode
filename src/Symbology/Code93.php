<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Symbol\LinearSymbol;

/**
 * Code 93 (ANSI/AIM BC5-1995), a compact successor to Code 39.
 *
 * Always carries the two mandatory check characters C and K. In standard
 * mode it encodes 0-9, A-Z, space and - . $ / + % (lower case is
 * upper-cased); with $fullAscii it encodes all 128 ASCII characters using
 * Code 93's dedicated shift characters.
 *
 * Width table from ANSI/AIM BC5 (as tabulated in Zint, BSD-3-Clause).
 */
final class Code93 extends AbstractSymbology
{
    /** Values 0-42 are the printable characters, 43-46 the shifts ($) (%) (/) (+). */
    private const CHARSET = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%\x01\x02\x03\x04";

    /** Bar/space widths (3 bars, 3 spaces, 9 modules) by value. */
    private const PATTERNS = [
        '131112', '111213', '111312', '111411', '121113', '121212', '121311', '111114',
        '131211', '141111', '211113', '211212', '211311', '221112', '221211', '231111',
        '112113', '112212', '112311', '122112', '132111', '111123', '111222', '111321',
        '121122', '131121', '212112', '212211', '211122', '211221', '221121', '222111',
        '112122', '112221', '122121', '123111', '121131', '311112', '311211', '321111',
        '112131', '113121', '211131', '121221', '312111', '311121', '122211',
    ];

    private const START = '111141';
    private const STOP = '1111411'; // stop character plus termination bar

    public function __construct(
        private readonly bool $fullAscii = false,
    ) {}

    public function name(): string
    {
        return $this->fullAscii ? 'Code 93 Extended' : 'Code 93';
    }

    protected function normalize(string $data): string
    {
        return $this->fullAscii ? $data : strtoupper($data);
    }

    protected function validate(string $data): void
    {
        if ($this->fullAscii) {
            FullAscii::expand($data, $this->name(), FullAscii::code93(...));

            return;
        }

        $this->assertAlphabet($data, substr(self::CHARSET, 0, 43));
    }

    protected function doEncode(string $data): LinearSymbol
    {
        $encoded = $this->fullAscii ? FullAscii::expand($data, $this->name(), FullAscii::code93(...)) : $data;

        $values = array_map(static fn(string $c): int => (int) strpos(self::CHARSET, $c), str_split($encoded));
        $values[] = self::weightedSum($values, 20);
        $values[] = self::weightedSum($values, 15);

        $pattern = self::START;
        foreach ($values as $value) {
            $pattern .= self::PATTERNS[$value];
        }

        return $this->fromWidths($pattern . self::STOP, FullAscii::printable($data));
    }

    /**
     * Check character: weights 1..$maxWeight cycling from the right, modulo 47.
     *
     * @param list<int> $values
     */
    private static function weightedSum(array $values, int $maxWeight): int
    {
        $sum = 0;
        foreach (array_reverse($values) as $i => $value) {
            $sum += $value * ($i % $maxWeight + 1);
        }

        return $sum % 47;
    }
}
