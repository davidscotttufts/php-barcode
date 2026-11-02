<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\DataMatrix;

/**
 * Marks a symbol as part of a sequence of up to 16 Data Matrix symbols that
 * readers reassemble into one message.
 */
final readonly class StructuredAppend
{
    /**
     * @param int $position 1-based position of this symbol in the sequence.
     * @param int $total    Number of symbols in the sequence (2-16).
     * @param int $fileId   Identifier shared by every symbol of the sequence (0-64515).
     */
    public function __construct(
        public int $position,
        public int $total,
        public int $fileId = 0,
    ) {
        if ($total < 2 || $total > 16) {
            throw new \InvalidArgumentException('A structured append sequence has 2 to 16 symbols.');
        }
        if ($position < 1 || $position > $total) {
            throw new \InvalidArgumentException(sprintf('The position must be between 1 and %d.', $total));
        }
        if ($fileId < 0 || $fileId > 254 * 254 - 1) {
            throw new \InvalidArgumentException('The file identifier must be between 0 and 64515.');
        }
    }

    /**
     * @return list<int>
     */
    public function codewords(): array
    {
        return [
            233,
            (($this->position - 1) << 4) | (17 - $this->total),
            intdiv($this->fileId, 254) + 1,
            $this->fileId % 254 + 1,
        ];
    }
}
