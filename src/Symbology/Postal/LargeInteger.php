<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\Postal;

/**
 * Minimal unsigned arbitrary-precision integer for the postal encoders, which
 * need more than 64 bits (Intelligent Mail: 102 bits; Mailmark: ~70 bits).
 * Avoids a hard dependency on ext-gmp or ext-bcmath.
 *
 * Stored as little-endian base-2^16 limbs.
 *
 * @internal
 */
final class LargeInteger
{
    private const BASE_BITS = 16;
    private const BASE = 1 << self::BASE_BITS;
    private const MASK = self::BASE - 1;

    /** @var list<int> */
    private array $limbs = [0];

    public function __construct(int $value = 0)
    {
        $this->add($value);
    }

    public static function fromDecimal(string $digits): self
    {
        $number = new self();
        foreach (str_split($digits) as $digit) {
            $number->multiplyAdd(10, (int) $digit);
        }

        return $number;
    }

    /**
     * $this = $this * $factor + $addend, for 0 <= $factor < 2^40 and 0 <= $addend < 2^46.
     */
    public function multiplyAdd(int $factor, int $addend = 0): self
    {
        if ($factor < 0 || $addend < 0) {
            throw new \InvalidArgumentException('LargeInteger only supports non-negative operands.');
        }

        $carry = $addend;
        foreach ($this->limbs as $i => $limb) {
            $value = $limb * $factor + $carry;
            $this->limbs[$i] = $value & self::MASK;
            $carry = $value >> self::BASE_BITS;
        }
        while ($carry > 0) {
            $this->limbs[] = $carry & self::MASK;
            $carry >>= self::BASE_BITS;
        }

        return $this;
    }

    public function add(int $addend): self
    {
        return $this->multiplyAdd(1, $addend);
    }

    /**
     * Divides in place by a small divisor and returns the remainder.
     */
    public function divideBy(int $divisor): int
    {
        if ($divisor < 1 || $divisor >= self::BASE * self::BASE) {
            throw new \InvalidArgumentException('Divisor out of range.');
        }

        $remainder = 0;
        $quotient = [];
        foreach (array_reverse($this->limbs) as $limb) {
            $value = ($remainder << self::BASE_BITS) | $limb;
            $quotient[] = intdiv($value, $divisor);
            $remainder = $value % $divisor;
        }

        // Back to little-endian, without leading zero limbs.
        $this->limbs = array_reverse($quotient);
        while (count($this->limbs) > 1 && end($this->limbs) === 0) {
            array_pop($this->limbs);
        }

        return $remainder;
    }

    /**
     * The lowest $count bits, most significant first.
     */
    public function toBits(int $count): string
    {
        $bits = '';
        for ($i = $count - 1; $i >= 0; $i--) {
            $limb = $this->limbs[intdiv($i, self::BASE_BITS)] ?? 0;
            $bits .= ($limb >> ($i % self::BASE_BITS)) & 1 ? '1' : '0';
        }

        return $bits;
    }

    public function toInt(): int
    {
        if (count($this->limbs) > 3) {
            throw new \OverflowException('Value does not fit in a native integer.');
        }

        $value = 0;
        foreach (array_reverse($this->limbs) as $limb) {
            $value = ($value << self::BASE_BITS) | $limb;
        }

        return $value;
    }
}
