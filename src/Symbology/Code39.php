<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Checksum\Mod43;
use PhpBarcode\Symbol\LinearSymbol;

/**
 * Code 39 (also known as Code 3 of 9).
 *
 * Encodes 0-9, A-Z, space and - . $ / + %. Lower-case input is upper-cased.
 * The optional mod-43 check character is appended when $withChecksum is true.
 */
final class Code39 extends AbstractWideNarrowSymbology
{
    /** Each character is 5 bars and 4 spaces, three of which are wide. */
    private const PATTERNS = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];

    private const GUARD = '*';

    public function __construct(
        private readonly bool $withChecksum = false,
        int $wideRatio = 3,
    ) {
        parent::__construct($wideRatio);
    }

    public function name(): string
    {
        return 'Code 39';
    }

    protected function normalize(string $data): string
    {
        return strtoupper($data);
    }

    protected function validate(string $data): void
    {
        $this->assertAlphabet($data, Mod43::ALPHABET);
    }

    protected function doEncode(string $data): LinearSymbol
    {
        $payload = $this->withChecksum ? $data . (new Mod43())->calculate($data) : $data;

        $characters = array_map(
            fn(string $character): string => $this->expand(self::PATTERNS[$character]),
            str_split(self::GUARD . $payload . self::GUARD),
        );

        // Characters are separated by a narrow inter-character gap.
        return $this->fromWidths(implode('1', $characters), $payload);
    }
}
