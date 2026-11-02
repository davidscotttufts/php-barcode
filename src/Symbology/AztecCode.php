<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\MatrixSymbol;
use PhpBarcode\Symbology\Aztec\AztecEncoder;
use PhpBarcode\Symbology\Aztec\HighLevelEncoder;

/**
 * Aztec Code (ISO/IEC 24778), a 2D code with a central bullseye that needs
 * no quiet zone. Used on boarding passes, rail tickets and vehicle papers.
 *
 * Encodes any bytes with an optimal mix of its text modes and binary shift.
 * Symbols range from 15×15 (compact, 1 layer) to 151×151 (full range, 32 layers).
 *
 * - $errorCorrection: minimum percentage of the symbol given to error correction (5-95, default 23).
 * - $layers:          force a layer count (compact 1-4, full range 1-32); null picks the smallest.
 * - $compact:         true for compact symbols only, false for full range only, null for either.
 * - $gs1:             GS1 data; the input is an element string whose variable-length
 *                     fields are separated by ASCII GS (0x1D), each encoded as FNC1.
 * - $eci:             Extended Channel Interpretation to declare. By default, ECI 26 (UTF-8)
 *                     is declared for input that is valid UTF-8 containing non-ASCII characters.
 *
 * Encoding after ZXing (Apache License 2.0) and Zint (BSD 3-Clause).
 */
final class AztecCode extends AbstractSymbology
{
    public const ECI_UTF8 = 26;

    private const GS = "\x1D";

    public function __construct(
        private readonly int $errorCorrection = 23,
        private readonly ?int $layers = null,
        private readonly ?bool $compact = null,
        private readonly bool $gs1 = false,
        private readonly ?int $eci = null,
    ) {
        if ($errorCorrection < 5 || $errorCorrection > 95) {
            throw new \InvalidArgumentException('Aztec error correction must be between 5% and 95%.');
        }
        if ($layers !== null) {
            $max = $compact === true ? AztecEncoder::MAX_COMPACT_LAYERS : AztecEncoder::MAX_LAYERS;
            if ($layers < 1 || $layers > $max) {
                throw new \InvalidArgumentException(sprintf('Aztec layers must be between 1 and %d.', $max));
            }
        }
        if ($eci !== null && ($eci < 0 || $eci > 999999)) {
            throw new \InvalidArgumentException('An ECI designator must be between 0 and 999999.');
        }
        if ($gs1 && $eci !== null) {
            throw new \InvalidArgumentException('GS1 Aztec codes cannot declare an ECI.');
        }
    }

    public function name(): string
    {
        return 'Aztec Code';
    }

    public function quietZone(): int
    {
        return 2;
    }

    protected function validate(string $data): void
    {
        if ($this->gs1 && str_starts_with($data, self::GS)) {
            throw InvalidDataException::invalidCharacter($this->name(), self::GS, 0);
        }
    }

    protected function doEncode(string $data): MatrixSymbol
    {
        $input = HighLevelEncoder::bytes($data);

        if ($this->gs1) {
            // FNC1 in first position marks GS1 data; each GS separator becomes FNC1 too.
            $input = array_map(static fn(int $byte): int => $byte === 0x1D ? HighLevelEncoder::FNC1 : $byte, $input);
            array_unshift($input, HighLevelEncoder::FNC1);
        }

        $bits = (new HighLevelEncoder())->encode($input, $this->eciFor($data));

        try {
            $matrix = (new AztecEncoder())->encode($bits, $this->errorCorrection, $this->layers, $this->compact);
        } catch (\OverflowException $e) {
            throw new InvalidDataException(sprintf('%s: %s', $this->name(), $e->getMessage()), 0, $e);
        }

        return MatrixSymbol::fromGrid(
            $matrix->toGrid(),
            self::printable($data),
            $this->name(),
            $this->quietZone(),
            finderPatterns: [$matrix->bullseye()],
        );
    }

    private function eciFor(string $data): ?int
    {
        if ($this->eci !== null || $this->gs1) {
            return $this->eci;
        }

        $isAscii = !preg_match('/[\x80-\xFF]/', $data);

        return !$isAscii && preg_match('//u', $data) === 1 ? self::ECI_UTF8 : null;
    }

    private static function printable(string $data): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $data);
    }
}
