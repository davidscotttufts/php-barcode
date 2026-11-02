<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

/**
 * EAN-8 (GTIN-8), the short retail barcode for small packages.
 */
final class Ean8 extends AbstractEan
{
    public function name(): string
    {
        return 'EAN-8';
    }

    public function quietZone(): int
    {
        return 7;
    }

    protected function length(): int
    {
        return 8;
    }

    protected function bitmap(string $digits): string
    {
        return EanTables::GUARD
            . EanTables::withParity(substr($digits, 0, 4), 'LLLL')
            . EanTables::CENTER_GUARD
            . implode('', array_map(EanTables::r(...), str_split(substr($digits, 4, 4))))
            . EanTables::GUARD;
    }

    protected function guards(): array
    {
        return [[0, 3], [31, 36], [64, 67]];
    }

    protected function textSegments(string $digits): array
    {
        return [
            ['text' => substr($digits, 0, 4), 'x' => 17, 'anchor' => 'middle'],
            ['text' => substr($digits, 4, 4), 'x' => 50, 'anchor' => 'middle'],
        ];
    }
}
