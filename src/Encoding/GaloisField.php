<?php

declare(strict_types=1);

namespace PhpBarcode\Encoding;

/**
 * Arithmetic in a binary Galois field GF(2^m), as used by Reed–Solomon error correction.
 *
 * Shared fields are available as named constructors; each symbology uses
 * the field and generator base its specification prescribes.
 */
final class GaloisField
{
    /** @var array<int, int> α^i for i in [0, size − 1) */
    private array $exp = [];

    /** @var array<int, int> i such that α^i = value */
    private array $log = [];

    /** @var array<string, self> */
    private static array $instances = [];

    /**
     * @param int $size          Number of elements, a power of two (e.g. 256).
     * @param int $primitive     Primitive polynomial, including the x^m term (e.g. 0x11D).
     * @param int $generatorBase First exponent of the RS generator polynomial (0 for QR, 1 for Data Matrix and Aztec).
     */
    public function __construct(
        public readonly int $size,
        public readonly int $primitive,
        public readonly int $generatorBase = 0,
    ) {
        if ($size < 4 || ($size & ($size - 1)) !== 0) {
            throw new \InvalidArgumentException('The field size must be a power of two.');
        }

        $this->log = array_fill(0, $size, 0);
        $value = 1;
        for ($i = 0; $i < $size; $i++) {
            $this->exp[$i] = $value;
            $value <<= 1;
            if ($value >= $size) {
                $value = ($value ^ $primitive) & ($size - 1);
            }
        }
        for ($i = 0; $i < $size - 1; $i++) {
            $this->log[$this->exp[$i]] = $i;
        }
    }

    /** QR Code: GF(256), x^8 + x^4 + x^3 + x^2 + 1, generator base 0. */
    public static function qrCode(): self
    {
        return self::$instances['qr'] ??= new self(256, 0x11D, 0);
    }

    /** Data Matrix ECC 200: GF(256), x^8 + x^5 + x^3 + x^2 + 1, generator base 1. */
    public static function dataMatrix(): self
    {
        return self::$instances['dm'] ??= new self(256, 0x12D, 1);
    }

    /** Aztec: GF(64), GF(256), GF(1024), GF(4096) and GF(16) for the mode message, generator base 1. */
    public static function aztec(int $wordSize): self
    {
        return self::$instances['aztec' . $wordSize] ??= match ($wordSize) {
            4 => new self(16, 0x13, 1),
            6 => new self(64, 0x43, 1),
            8 => new self(256, 0x12D, 1),
            10 => new self(1024, 0x409, 1),
            12 => new self(4096, 0x1069, 1),
            default => throw new \InvalidArgumentException('Aztec word sizes are 4, 6, 8, 10 or 12 bits.'),
        };
    }

    public function exp(int $power): int
    {
        $order = $this->size - 1;

        // Normalize negative powers too: α^-1 = α^(order-1).
        return $this->exp[(($power % $order) + $order) % $order];
    }

    public function log(int $value): int
    {
        if ($value === 0) {
            throw new \DomainException('log(0) is undefined.');
        }

        return $this->log[$value];
    }

    public function multiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return $this->exp[($this->log[$a] + $this->log[$b]) % ($this->size - 1)];
    }
}
