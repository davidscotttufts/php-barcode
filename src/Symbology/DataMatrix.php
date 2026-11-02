<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Encoding\GaloisField;
use PhpBarcode\Encoding\ReedSolomon;
use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\MatrixSymbol;
use PhpBarcode\Symbology\DataMatrix\DataMatrixShape;
use PhpBarcode\Symbology\DataMatrix\HighLevelEncoder;
use PhpBarcode\Symbology\DataMatrix\Placement;
use PhpBarcode\Symbology\DataMatrix\StructuredAppend;
use PhpBarcode\Symbology\DataMatrix\SymbolSize;

/**
 * Data Matrix ECC 200 (ISO/IEC 16022), with the DMRE rectangular extensions
 * of ISO/IEC 21471.
 *
 * - All 24 square and 6 rectangular sizes, plus 18 DMRE sizes when enabled.
 * - Automatic switching between the ASCII, C40, Text, X12, EDIFACT and
 *   Base 256 encodation schemes for a compact symbol.
 * - GS1 DataMatrix: pass `gs1: true` and separate variable-length element
 *   strings with ASCII GS (0x1D); each GS is encoded as FNC1.
 * - UTF-8 text is flagged with ECI 26 automatically so readers decode it correctly.
 * - Macro 05/06 headers ("[)>␞05␝ … ␞␄") are compressed to one codeword.
 * - Structured append across up to 16 symbols.
 *
 * Written from the ISO specifications, with reference to the open-source
 * ZXing (Apache-2.0), Zint (BSD-3-Clause) and libdmtx (BSD-2-Clause)
 * implementations.
 */
final class DataMatrix extends AbstractSymbology
{
    /** Pass as $eci to never emit an ECI designator. */
    public const NO_ECI = -1;

    private const UTF8_ECI = 26;
    private const MACRO_TRAILER = "\x1E\x04";
    private const MACROS = ["[)>\x1E05\x1D" => 236, "[)>\x1E06\x1D" => 237];

    private readonly ?SymbolSize $fixedSize;

    /**
     * @param DataMatrixShape         $shape            Which symbol shapes may be chosen.
     * @param string|null             $size             Force one size, e.g. "32x32" or "16x48".
     * @param bool                    $gs1              GS1 DataMatrix: FNC1 first, ASCII GS (0x1D) encoded as FNC1.
     * @param int|null                $eci              ECI designator; null emits ECI 26 for non-ASCII UTF-8, NO_ECI never.
     * @param bool                    $dmre             Allow DMRE rectangular sizes (ISO/IEC 21471).
     * @param StructuredAppend|null   $structuredAppend Position of this symbol in a multi-symbol sequence.
     */
    public function __construct(
        private readonly DataMatrixShape $shape = DataMatrixShape::Square,
        ?string $size = null,
        private readonly bool $gs1 = false,
        private readonly ?int $eci = null,
        private readonly bool $dmre = false,
        private readonly ?StructuredAppend $structuredAppend = null,
    ) {
        $this->fixedSize = $size === null ? null : SymbolSize::fromName($size);

        if ($this->fixedSize?->dmre === true && !$dmre) {
            throw new \InvalidArgumentException(sprintf('%s is a DMRE size; pass dmre: true to use it.', $this->fixedSize->name()));
        }
        if ($eci !== null && $eci !== self::NO_ECI && ($eci < 0 || $eci > 999999)) {
            throw new \InvalidArgumentException('ECI designators range from 0 to 999999.');
        }
    }

    public function name(): string
    {
        return $this->gs1 ? 'GS1 DataMatrix' : 'Data Matrix';
    }

    public function quietZone(): int
    {
        return 2;
    }

    protected function validate(string $data): void
    {
        if (!$this->gs1) {
            return;
        }

        $this->assertAlphabet($data, implode('', array_map('chr', range(0x1D, 0x7E))) . "\x1D");
        if ($data[0] === "\x1D") {
            throw InvalidDataException::invalidCharacter($this->name(), $data[0], 0);
        }
    }

    protected function doEncode(string $data): MatrixSymbol
    {
        [$prefix, $message] = $this->header($data);

        $sizes = $this->candidateSizes();
        $capacity = static function (int $codewords) use ($sizes): ?int {
            foreach ($sizes as $size) {
                if ($size->dataCodewords >= $codewords) {
                    return $size->dataCodewords;
                }
            }

            return null;
        };

        try {
            $dataCodewords = (new HighLevelEncoder($message, $capacity, $this->gs1))->encode($prefix);
        } catch (\OverflowException) {
            $largest = end($sizes);
            throw new InvalidDataException(sprintf(
                'The data is too long for %s (at most %d codewords in %s).',
                $this->name(),
                $largest === false ? 0 : $largest->dataCodewords,
                $largest === false ? 'any allowed size' : $largest->name(),
            ));
        }

        $size = $this->sizeFor(count($dataCodewords), $sizes);

        return new MatrixSymbol(
            Placement::symbol($size, $this->withErrorCorrection($dataCodewords, $size)),
            (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $data),
            $this->name(),
            $this->quietZone(),
        );
    }

    /**
     * Encodes $data and returns the codewords (data then error correction),
     * for inspection and testing.
     *
     * @return array{size: SymbolSize, data: list<int>, codewords: list<int>}
     */
    public function codewords(string $data): array
    {
        [$prefix, $message] = $this->header($data);
        $sizes = $this->candidateSizes();
        $capacity = static function (int $n) use ($sizes): ?int {
            foreach ($sizes as $size) {
                if ($size->dataCodewords >= $n) {
                    return $size->dataCodewords;
                }
            }

            return null;
        };
        $dataCodewords = (new HighLevelEncoder($message, $capacity, $this->gs1))->encode($prefix);
        $size = $this->sizeFor(count($dataCodewords), $sizes);

        return ['size' => $size, 'data' => $dataCodewords, 'codewords' => $this->withErrorCorrection($dataCodewords, $size)];
    }

    /**
     * Leading codewords (structured append, FNC1 / macro, ECI) and the remaining message.
     *
     * @return array{list<int>, string}
     */
    private function header(string $data): array
    {
        $prefix = $this->structuredAppend?->codewords() ?? [];

        if ($this->gs1) {
            $prefix[] = 232;
        } else {
            foreach (self::MACROS as $header => $codeword) {
                if (str_starts_with($data, $header) && str_ends_with($data, self::MACRO_TRAILER)
                    && strlen($data) >= strlen($header) + strlen(self::MACRO_TRAILER)) {
                    $prefix[] = $codeword;
                    $data = substr($data, strlen($header), -strlen(self::MACRO_TRAILER));
                    break;
                }
            }
        }

        $eci = $this->eci;
        if ($eci === null && !$this->gs1 && preg_match('/[\x80-\xFF]/', $data) === 1 && preg_match('//u', $data) === 1) {
            $eci = self::UTF8_ECI;
        }
        if ($eci !== null && $eci !== self::NO_ECI) {
            array_push($prefix, 241, ...self::eciCodewords($eci));
        }

        return [$prefix, $data];
    }

    /**
     * @return list<int>
     */
    private static function eciCodewords(int $eci): array
    {
        if ($eci <= 126) {
            return [$eci + 1];
        }
        if ($eci <= 16382) {
            $v = $eci - 127;

            return [intdiv($v, 254) + 128, $v % 254 + 1];
        }
        $v = $eci - 16383;

        return [intdiv($v, 64516) + 192, intdiv($v, 254) % 254 + 1, $v % 254 + 1];
    }

    /**
     * Allowed sizes, smallest capacity first (squares win ties).
     *
     * @return list<SymbolSize>
     */
    private function candidateSizes(): array
    {
        if ($this->fixedSize !== null) {
            return [$this->fixedSize];
        }

        $sizes = array_values(array_filter(
            SymbolSize::all(),
            fn(SymbolSize $size): bool => $this->shape->allows($size) && ($this->dmre || !$size->dmre),
        ));

        usort($sizes, static fn(SymbolSize $a, SymbolSize $b): int => [$a->dataCodewords, $a->rows * $a->columns, $a->isSquare() ? 0 : 1]
            <=> [$b->dataCodewords, $b->rows * $b->columns, $b->isSquare() ? 0 : 1]);

        return $sizes;
    }

    /**
     * @param list<SymbolSize> $sizes
     */
    private function sizeFor(int $codewords, array $sizes): SymbolSize
    {
        foreach ($sizes as $size) {
            if ($size->dataCodewords === $codewords) {
                return $size;
            }
        }

        throw new \LogicException('The encoder produced a codeword count that matches no symbol size.');
    }

    /**
     * Appends Reed–Solomon codewords, interleaving blocks for the larger sizes.
     *
     * @param list<int> $data
     *
     * @return list<int>
     */
    private function withErrorCorrection(array $data, SymbolSize $size): array
    {
        $rs = new ReedSolomon(GaloisField::dataMatrix());
        $blocks = $size->blocks;
        $ecPerBlock = intdiv($size->ecCodewords, $blocks);
        $dataCount = count($data);
        $result = $data;

        for ($block = 0; $block < $blocks; $block++) {
            $blockData = [];
            for ($i = $block; $i < $dataCount; $i += $blocks) {
                $blockData[] = $data[$i];
            }
            foreach ($rs->encode($blockData, $ecPerBlock) as $j => $codeword) {
                $result[$dataCount + $j * $blocks + $block] = $codeword;
            }
        }

        ksort($result);

        return array_values($result);
    }
}
