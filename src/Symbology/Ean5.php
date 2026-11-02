<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

/**
 * EAN-5 five-digit add-on (suggested retail price on books).
 */
final class Ean5 extends AbstractEanAddOn
{
    private const PARITY = [
        'GGLLL', 'GLGLL', 'GLLGL', 'GLLLG', 'LGGLL',
        'LLGGL', 'LLLGG', 'LGLGL', 'LGLLG', 'LLGLG',
    ];

    public function name(): string
    {
        return 'EAN-5';
    }

    protected function digits(): int
    {
        return 5;
    }

    protected function parity(string $data): string
    {
        $d = array_map('intval', str_split($data));
        $check = (3 * ($d[0] + $d[2] + $d[4]) + 9 * ($d[1] + $d[3])) % 10;

        return self::PARITY[$check];
    }
}
