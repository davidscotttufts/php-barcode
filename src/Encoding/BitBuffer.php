<?php

declare(strict_types=1);

namespace PhpBarcode\Encoding;

/**
 * An append-only, most-significant-bit-first bit string.
 */
final class BitBuffer implements \Countable
{
    private string $bits = '';

    /**
     * Appends the lowest $length bits of $value, most significant first.
     */
    public function append(int $value, int $length): self
    {
        if ($length < 0 || $length > 62 || ($length < 62 && $value >> $length !== 0) || $value < 0) {
            throw new \InvalidArgumentException(sprintf('%d does not fit in %d bits.', $value, $length));
        }

        if ($length > 0) {
            $this->bits .= str_pad(decbin($value), $length, '0', STR_PAD_LEFT);
        }

        return $this;
    }

    public function appendBits(string $bits): self
    {
        if (strspn($bits, '01') !== strlen($bits)) {
            throw new \InvalidArgumentException('Bits must be 0 or 1.');
        }
        $this->bits .= $bits;

        return $this;
    }

    public function count(): int
    {
        return strlen($this->bits);
    }

    public function bit(int $index): bool
    {
        return $this->bits[$index] === '1';
    }

    public function toString(): string
    {
        return $this->bits;
    }

    /**
     * Splits into $wordSize-bit integers, padding the last word with zeros.
     *
     * @return list<int>
     */
    public function toWords(int $wordSize = 8): array
    {
        if ($wordSize < 1) {
            throw new \InvalidArgumentException('The word size must be at least 1 bit.');
        }

        if ($this->bits === '') {
            return [];
        }

        $padded = str_pad($this->bits, (int) ceil(strlen($this->bits) / $wordSize) * $wordSize, '0');

        return array_map(static fn(string $word): int => (int) bindec($word), str_split($padded, $wordSize));
    }
}
