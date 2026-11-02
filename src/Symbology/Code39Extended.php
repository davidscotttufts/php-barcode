<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Symbol\LinearSymbol;

/**
 * Code 39 Full ASCII ("Code 39 Extended"): all 128 ASCII characters, encoded
 * as shift pairs of ordinary Code 39 characters. Readers must have Full ASCII
 * mode enabled to decode it.
 */
final class Code39Extended extends AbstractSymbology
{
    public function __construct(
        private readonly bool $withChecksum = false,
        private readonly int $wideRatio = 3,
    ) {}

    public function name(): string
    {
        return 'Code 39 Extended';
    }

    protected function validate(string $data): void
    {
        FullAscii::expand($data, $this->name(), FullAscii::code39(...));
    }

    protected function doEncode(string $data): LinearSymbol
    {
        $symbol = (new Code39($this->withChecksum, $this->wideRatio))->encode(FullAscii::expand($data, $this->name(), FullAscii::code39(...)));
        \assert($symbol instanceof LinearSymbol);

        return $symbol->withText(FullAscii::printable($data))->withSymbology($this->name());
    }
}
