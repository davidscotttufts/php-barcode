<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Symbology\Pdf417\HighLevelEncoder;
use PhpBarcode\Symbology\Pdf417\MacroSegment;

/**
 * Shared data handling for PDF417 and MicroPDF417: ECI selection, compaction
 * and Macro PDF417 control blocks.
 */
abstract class AbstractPdf417 extends AbstractSymbology
{
    /** ECI assignment number for UTF-8. */
    public const ECI_UTF8 = 26;

    /**
     * @param int|false|null    $eci   ECI to declare: null adds UTF-8 (26) automatically when the data is
     *                                 non-ASCII UTF-8, false never adds one, an int is always emitted.
     * @param MacroSegment|null $macro Structured-append segment information.
     */
    public function __construct(
        protected readonly int|false|null $eci = null,
        protected readonly ?MacroSegment $macro = null,
    ) {
        if (is_int($eci) && ($eci < 0 || $eci > 811799)) {
            throw new \InvalidArgumentException('ECI numbers range from 0 to 811799.');
        }
    }

    protected function validate(string $data): void
    {
        // Every byte sequence can be encoded; capacity is checked while encoding.
    }

    /**
     * Data codewords for the payload, including any ECI header.
     *
     * @return list<int>
     */
    protected function dataCodewords(string $data, bool $startInByteMode): array
    {
        return HighLevelEncoder::encode($data, $this->resolveEci($data), $startInByteMode);
    }

    /**
     * @return list<int>
     */
    protected function macroCodewords(): array
    {
        return $this->macro?->codewords() ?? [];
    }

    /**
     * Human-readable text for the symbol: the data when it is valid UTF-8.
     */
    protected function humanReadable(string $data): string
    {
        return self::isUtf8($data) ? $data : '';
    }

    private function resolveEci(string $data): ?int
    {
        if ($this->eci === false) {
            return null;
        }
        if (is_int($this->eci)) {
            return $this->eci;
        }

        $isAscii = !preg_match('/[\x80-\xFF]/', $data);

        return !$isAscii && self::isUtf8($data) ? self::ECI_UTF8 : null;
    }

    private static function isUtf8(string $data): bool
    {
        return preg_match('//u', $data) === 1;
    }
}
