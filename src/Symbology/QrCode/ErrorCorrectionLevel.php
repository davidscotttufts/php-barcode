<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\QrCode;

/**
 * QR Code error correction levels: the approximate share of the symbol that
 * can be damaged and still be read.
 */
enum ErrorCorrectionLevel: string
{
    /** Recovers about 7% of codewords. */
    case L = 'L';

    /** Recovers about 15% of codewords. The usual default. */
    case M = 'M';

    /** Recovers about 25% of codewords. */
    case Q = 'Q';

    /** Recovers about 30% of codewords. Use it when overlaying a logo. */
    case H = 'H';

    public static function fromName(string $name): self
    {
        return self::tryFrom(strtoupper(trim($name)))
            ?? throw new \InvalidArgumentException(sprintf('Unknown error correction level "%s"; use L, M, Q or H.', $name));
    }

    /**
     * Two-bit indicator used in the QR format information.
     */
    public function formatBits(): int
    {
        return match ($this) {
            self::L => 1,
            self::M => 0,
            self::Q => 3,
            self::H => 2,
        };
    }

    /**
     * Position in ascending strength order (L=0 … H=3), used to index tables.
     */
    public function ordinal(): int
    {
        return match ($this) {
            self::L => 0,
            self::M => 1,
            self::Q => 2,
            self::H => 3,
        };
    }

    /**
     * The next stronger level, or null for H.
     */
    public function stronger(): ?self
    {
        return match ($this) {
            self::L => self::M,
            self::M => self::Q,
            self::Q => self::H,
            self::H => null,
        };
    }
}
