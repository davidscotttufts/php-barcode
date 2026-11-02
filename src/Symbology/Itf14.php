<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Checksum\Mod10;
use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\LinearSymbol;

/**
 * ITF-14: a GTIN-14 for shipping cartons, as Interleaved 2 of 5 with bearer bars.
 *
 * Accepts 13 digits (the check digit is added) or 14 (it is verified).
 */
final class Itf14 extends AbstractSymbology
{
    /**
     * @param BearerBars $bearers     Bearer bar style.
     * @param int        $bearerWidth Bearer thickness in modules (GS1 minimum: 2× the narrow bar; 5 is typical).
     */
    public function __construct(
        private readonly BearerBars $bearers = BearerBars::Box,
        private readonly int $bearerWidth = 5,
        private readonly int $wideRatio = 3,
    ) {
        if ($bearerWidth < 2) {
            throw new \InvalidArgumentException('ITF-14 bearer bars must be at least 2 modules thick.');
        }
    }

    public function name(): string
    {
        return 'ITF-14';
    }

    protected function normalize(string $data): string
    {
        return str_replace([' ', '-'], '', $data);
    }

    protected function validate(string $data): void
    {
        $this->assertAlphabet($data, '0123456789');

        if (strlen($data) !== 13 && strlen($data) !== 14) {
            throw InvalidDataException::invalidLength($this->name(), '14 digits (or 13 without the check digit)', strlen($data));
        }
        if (strlen($data) === 14) {
            $expected = (new Mod10())->calculate(substr($data, 0, 13));
            if ($expected !== $data[13]) {
                throw InvalidDataException::invalidCheckDigit($this->name(), $expected, $data[13]);
            }
        }
    }

    protected function doEncode(string $data): LinearSymbol
    {
        $gtin = strlen($data) === 14 ? $data : $data . (new Mod10())->calculate($data);

        $itf = (new Interleaved2of5(false, $this->wideRatio))->encode($gtin);
        \assert($itf instanceof LinearSymbol);

        return new LinearSymbol(
            $itf->widths,
            $gtin,
            $this->name(),
            $this->quietZone(),
            bearerWidth: $this->bearers === BearerBars::None ? 0 : $this->bearerWidth,
            bearerBox: $this->bearers === BearerBars::Box,
        );
    }
}
