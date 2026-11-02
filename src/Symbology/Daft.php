<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Symbol\HeightModulatedSymbol;

/**
 * Generic 4-state barcode written directly in DAFT notation
 * (D = descender, A = ascender, F = full, T = tracker), e.g. "FADTTFAD".
 *
 * Useful for postal codes this library doesn't encode natively, or for
 * reproducing a bar sequence produced elsewhere.
 */
final class Daft extends AbstractFourStateSymbology
{
    public function __construct(
        private readonly float $trackerRatio = 0.25,
    ) {
        if ($trackerRatio <= 0.0 || $trackerRatio >= 1.0) {
            throw new \InvalidArgumentException('The tracker ratio must be between 0 and 1.');
        }
    }

    public function name(): string
    {
        return 'DAFT';
    }

    protected function normalize(string $data): string
    {
        return strtoupper(str_replace(' ', '', $data));
    }

    protected function validate(string $data): void
    {
        $this->assertAlphabet($data, 'DAFT');
    }

    protected function trackerExtent(): array
    {
        return [(1 - $this->trackerRatio) / 2, (1 + $this->trackerRatio) / 2];
    }

    protected function doEncode(string $data): HeightModulatedSymbol
    {
        return $this->fromStates($data, '');
    }
}
