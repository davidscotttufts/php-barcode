<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\Aztec;

use PhpBarcode\Encoding\BitBuffer;

/**
 * One step of an Aztec high-level encoding, kept as an immutable linked list
 * so that many candidate encodings can share their common prefix.
 *
 * A token is either a plain code ($bitCount bits of $value) or a binary-shift
 * run of $byteCount bytes starting at input position $start.
 *
 * @internal
 */
final readonly class Token
{
    private function __construct(
        public ?Token $previous,
        public int $value,
        public int $bitCount,
        public int $start = -1,
        public int $byteCount = 0,
    ) {}

    public static function add(?self $previous, int $value, int $bitCount): self
    {
        return new self($previous, $value, $bitCount);
    }

    public static function addBinaryShift(?self $previous, int $start, int $byteCount): self
    {
        return new self($previous, 0, 0, $start, $byteCount);
    }

    /**
     * @param list<int> $input
     */
    public function appendTo(BitBuffer $bits, array $input): void
    {
        if ($this->byteCount === 0) {
            $bits->append($this->value, $this->bitCount);

            return;
        }

        $count = $this->byteCount;
        for ($i = 0; $i < $count; $i++) {
            // A header precedes the first byte, and byte 31 when the run is 32-62 bytes long.
            if ($i === 0 || ($i === 31 && $count <= 62)) {
                $bits->append(31, 5); // B/S
                if ($count > 62) {
                    $bits->append($count - 31, 16); // 5 zero bits, then an 11-bit length
                } elseif ($i === 0) {
                    $bits->append(min($count, 31), 5);
                } else {
                    $bits->append($count - 31, 5);
                }
            }
            $bits->append($input[$this->start + $i], 8);
        }
    }
}
