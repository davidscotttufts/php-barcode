<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Checksum\Mod10;
use PhpBarcode\Symbol\LinearSymbol;

/**
 * Interleaved 2 of 5 (ITF), a dense numeric-only symbology.
 *
 * Digits are encoded in pairs: the first in the bars, the second in the
 * spaces. Odd-length input is padded with a leading zero (the original
 * script silently dropped the last digit instead).
 */
final class Interleaved2of5 extends AbstractWideNarrowSymbology
{
    private const DIGITS = [
        'nnwwn', 'wnnnw', 'nwnnw', 'wwnnn', 'nnwnw',
        'wnwnn', 'nwwnn', 'nnnww', 'wnnwn', 'nwnwn',
    ];

    private const START = 'nnnn';
    private const STOP = 'wnn';

    public function __construct(
        private readonly bool $withChecksum = false,
        int $wideRatio = 3,
    ) {
        parent::__construct($wideRatio);
    }

    public function name(): string
    {
        return 'Interleaved 2 of 5';
    }

    protected function validate(string $data): void
    {
        $this->assertAlphabet($data, '0123456789');
    }

    protected function doEncode(string $data): LinearSymbol
    {
        $payload = $this->withChecksum ? $data . (new Mod10())->calculate($data) : $data;

        if (strlen($payload) % 2 !== 0) {
            $payload = '0' . $payload;
        }

        $pattern = self::START;
        foreach (str_split($payload, 2) as $pair) {
            $bars = self::DIGITS[(int) $pair[0]];
            $spaces = self::DIGITS[(int) $pair[1]];
            for ($i = 0; $i < 5; $i++) {
                $pattern .= $bars[$i] . $spaces[$i];
            }
        }
        $pattern .= self::STOP;

        return $this->fromWidths($this->expand($pattern), $payload);
    }
}
