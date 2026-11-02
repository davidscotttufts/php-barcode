<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

/**
 * UPC-A, the 12-digit North American retail barcode.
 *
 * Structurally an EAN-13 with a leading zero: all six left-hand digits use
 * odd (L) parity. The number-system digit and the check digit are printed
 * outside the bars, and their bar patterns extend down like guard bars.
 */
final class UpcA extends AbstractEan
{
    public function name(): string
    {
        return 'UPC-A';
    }

    public function quietZone(): int
    {
        return 9;
    }

    protected function rightQuietZone(): int
    {
        return 9;
    }

    protected function minimumAddOnGap(): int
    {
        return 9;
    }

    protected function length(): int
    {
        return 12;
    }

    protected function bitmap(string $digits): string
    {
        return EanTables::GUARD
            . EanTables::withParity(substr($digits, 0, 6), 'LLLLLL')
            . EanTables::CENTER_GUARD
            . implode('', array_map(EanTables::r(...), str_split(substr($digits, 6, 6))))
            . EanTables::GUARD;
    }

    protected function guards(): array
    {
        return [[0, 10], [45, 50], [85, 95]];
    }

    protected function textSegments(string $digits): array
    {
        return [
            ['text' => $digits[0], 'x' => -2, 'anchor' => 'end'],
            ['text' => substr($digits, 1, 5), 'x' => 27.5, 'anchor' => 'middle'],
            ['text' => substr($digits, 6, 5), 'x' => 67.5, 'anchor' => 'middle'],
            ['text' => $digits[11], 'x' => 97, 'anchor' => 'start'],
        ];
    }
}
