<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Gs1\ElementString;
use PhpBarcode\Symbol\MatrixSymbol;
use PhpBarcode\Symbol\Symbol;

/**
 * Decorator that lets a GS1-mode symbology accept human-readable GS1 data.
 *
 * Input such as "(01)09506000134352(17)251231(10)ABC123" is validated against
 * the GS1 Application Identifier dictionary, converted to the raw form with
 * GS separators, and passed to the wrapped symbology:
 *
 *     new Gs1(new DataMatrix(gs1: true))
 *     new Gs1(new QrCode(gs1: true))
 */
final class Gs1 implements SymbologyInterface
{
    public function __construct(
        private readonly SymbologyInterface $symbology,
        private readonly bool $checkAssociations = true,
    ) {}

    public function name(): string
    {
        return $this->symbology->name();
    }

    public function encode(string $data): Symbol
    {
        $elements = ElementString::parse($data, $this->checkAssociations);
        $symbol = $this->symbology->encode($elements->toEncodable());

        return $symbol instanceof MatrixSymbol ? $symbol->withText($elements->toHri()) : $symbol;
    }

    public function supports(string $data): bool
    {
        try {
            $this->encode($data);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
