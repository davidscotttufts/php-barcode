<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\MatrixSymbol;
use PhpBarcode\Symbology\Aztec\AztecEncoder;

/**
 * Aztec Rune: an 11×11 Aztec bullseye that carries a single value from 0 to 255,
 * for marking items where a full symbol would be too large.
 *
 * The input is the decimal value, e.g. "42".
 */
final class AztecRune extends AbstractSymbology
{
    public function name(): string
    {
        return 'Aztec Rune';
    }

    public function quietZone(): int
    {
        return 2;
    }

    protected function normalize(string $data): string
    {
        return trim($data);
    }

    protected function validate(string $data): void
    {
        $this->assertAlphabet($data, '0123456789');

        if (strlen($data) > 3 || (int) $data > 255) {
            throw new InvalidDataException(sprintf('%s encodes a value from 0 to 255, got "%s".', $this->name(), $data));
        }
    }

    protected function doEncode(string $data): MatrixSymbol
    {
        $matrix = (new AztecEncoder())->rune((int) $data);

        return MatrixSymbol::fromGrid(
            $matrix->toGrid(),
            (string) (int) $data,
            $this->name(),
            $this->quietZone(),
            finderPatterns: [$matrix->bullseye()],
        );
    }
}
