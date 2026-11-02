<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\Pdf417;

/**
 * Macro PDF417 / MicroPDF417 structured append: one segment of a message
 * that is split across several symbols and reassembled by the reader.
 */
final readonly class MacroSegment
{
    public const MACRO_MARKER = 928;
    public const OPTIONAL_FIELD = 923;
    public const LAST_SEGMENT = 922;
    private const FIELD_SEGMENT_COUNT = 1;

    /**
     * @param int    $index  1-based position of this symbol in the sequence.
     * @param int    $count  Total number of symbols in the sequence (2-99999).
     * @param string $fileId Identifier shared by all symbols of the message, as digits in groups of three (each 000-899).
     */
    public function __construct(
        public int $index,
        public int $count,
        public string $fileId = '001',
    ) {
        if ($count < 2 || $count > 99999) {
            throw new \InvalidArgumentException('A Macro PDF417 sequence has 2 to 99999 segments.');
        }
        if ($index < 1 || $index > $count) {
            throw new \InvalidArgumentException(sprintf('Segment index must be between 1 and %d.', $count));
        }
        if ($fileId === '' || !ctype_digit($fileId) || strlen($fileId) % 3 !== 0 || strlen($fileId) > 30) {
            throw new \InvalidArgumentException('The file ID must be 3 to 30 digits, in groups of three.');
        }
        foreach (str_split($fileId, 3) as $group) {
            if ((int) $group > 899) {
                throw new \InvalidArgumentException('Each three-digit group of the file ID must be 000-899.');
            }
        }
    }

    public function isLast(): bool
    {
        return $this->index === $this->count;
    }

    /**
     * The Macro PDF417 control block, placed after the data and padding.
     *
     * @return list<int>
     */
    public function codewords(): array
    {
        $codewords = [
            self::MACRO_MARKER,
            // Segment index, 0-based, as five numerically compacted digits.
            intdiv(100000 + $this->index - 1, 900),
            (100000 + $this->index - 1) % 900,
        ];

        foreach (str_split($this->fileId, 3) as $group) {
            $codewords[] = (int) $group;
        }

        array_push(
            $codewords,
            self::OPTIONAL_FIELD,
            self::FIELD_SEGMENT_COUNT,
            intdiv(100000 + $this->count, 900),
            (100000 + $this->count) % 900,
        );

        if ($this->isLast()) {
            $codewords[] = self::LAST_SEGMENT;
        }

        return $codewords;
    }
}
