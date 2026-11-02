<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

/**
 * EAN-13 (also GTIN-13), the international retail barcode.
 *
 *     (new Ean13())->encode('400638133393');        // check digit added
 *     (new Ean13())->encode('9781234567897+51299'); // with EAN-5 add-on
 */
final class Ean13 extends AbstractEan
{
    /** The first digit is encoded implicitly by the L/G parity of the next six. */
    private const PARITY = [
        'LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG',
        'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL',
    ];

    public function name(): string
    {
        return 'EAN-13';
    }

    public function quietZone(): int
    {
        return 11;
    }

    protected function length(): int
    {
        return 13;
    }

    protected function bitmap(string $digits): string
    {
        return EanTables::GUARD
            . EanTables::withParity(substr($digits, 1, 6), self::PARITY[(int) $digits[0]])
            . EanTables::CENTER_GUARD
            . implode('', array_map(EanTables::r(...), str_split(substr($digits, 7, 6))))
            . EanTables::GUARD;
    }

    protected function guards(): array
    {
        return [[0, 3], [45, 50], [92, 95]];
    }

    protected function textSegments(string $digits): array
    {
        return [
            ['text' => $digits[0], 'x' => -2, 'anchor' => 'end'],
            ['text' => substr($digits, 1, 6), 'x' => 24, 'anchor' => 'middle'],
            ['text' => substr($digits, 7, 6), 'x' => 71, 'anchor' => 'middle'],
        ];
    }
}
