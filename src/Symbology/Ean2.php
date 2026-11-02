<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

/**
 * EAN-2 two-digit add-on (periodical issue numbers).
 */
final class Ean2 extends AbstractEanAddOn
{
    private const PARITY = ['LL', 'LG', 'GL', 'GG'];

    public function name(): string
    {
        return 'EAN-2';
    }

    protected function digits(): int
    {
        return 2;
    }

    protected function parity(string $data): string
    {
        return self::PARITY[(int) $data % 4];
    }
}
