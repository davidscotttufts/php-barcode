<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\QrCode;

/**
 * Structured Append header: marks a symbol as one part of a message split
 * across up to 16 QR Codes, which readers reassemble in order.
 */
final readonly class StructuredAppend
{
    /**
     * @param int $index  Zero-based position of this symbol in the sequence (0-15).
     * @param int $total  Number of symbols in the sequence (2-16).
     * @param int $parity XOR of every byte of the complete message (see parityOf()).
     */
    public function __construct(
        public int $index,
        public int $total,
        public int $parity,
    ) {
        if ($total < 2 || $total > 16) {
            throw new \InvalidArgumentException('A structured append sequence has 2 to 16 symbols.');
        }
        if ($index < 0 || $index >= $total) {
            throw new \InvalidArgumentException(sprintf('The symbol index must be between 0 and %d.', $total - 1));
        }
        if ($parity < 0 || $parity > 255) {
            throw new \InvalidArgumentException('The parity must be a byte (0-255).');
        }
    }

    /**
     * The parity byte for a complete message: all of its bytes XORed together.
     */
    public static function parityOf(string $message): int
    {
        $parity = 0;
        foreach (str_split($message) as $byte) {
            $parity ^= ord($byte);
        }

        return $parity;
    }
}
