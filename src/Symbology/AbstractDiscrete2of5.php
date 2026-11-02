<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Checksum\Mod10;
use PhpBarcode\Symbol\LinearSymbol;

/**
 * Base for the discrete (non-interleaved) 2 of 5 codes, where every digit is
 * five bars, two of them wide, separated by narrow spaces.
 */
abstract class AbstractDiscrete2of5 extends AbstractWideNarrowSymbology
{
    private const DIGITS = [
        'nnwwn', 'wnnnw', 'nwnnw', 'wwnnn', 'nnwnw',
        'wnwnn', 'nwwnn', 'nnnww', 'wnnwn', 'nwnwn',
    ];

    public function __construct(
        private readonly bool $withChecksum = false,
        int $wideRatio = 3,
    ) {
        parent::__construct($wideRatio);
    }

    /** Start pattern as alternating bar/space elements, ending with a space. */
    abstract protected function start(): string;

    /** Stop pattern as alternating bar/space elements, starting and ending with a bar. */
    abstract protected function stop(): string;

    protected function validate(string $data): void
    {
        $this->assertAlphabet($data, '0123456789');
    }

    protected function doEncode(string $data): LinearSymbol
    {
        $payload = $this->withChecksum ? $data . (new Mod10())->calculate($data) : $data;

        $pattern = $this->start();
        foreach (str_split($payload) as $digit) {
            // Bars from the digit pattern, each followed by a narrow space.
            $pattern .= implode('', array_map(static fn(string $bar): string => $bar . 'n', str_split(self::DIGITS[(int) $digit])));
        }

        return $this->fromWidths($this->expand($pattern . $this->stop()), $payload);
    }
}
