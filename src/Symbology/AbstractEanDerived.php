<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\LinearSymbol;

/**
 * Base for identifiers that are printed as EAN-13 symbols (ISBN, ISSN, ISMN).
 *
 * Subclasses convert their own number format to 12 or 13 EAN digits; an EAN-2
 * or EAN-5 add-on (e.g. a price) may follow after "+" or a space. The symbol
 * gets the identifier printed above it, as the standards recommend.
 */
abstract class AbstractEanDerived extends AbstractSymbology
{
    public function __construct(
        private readonly Ean13 $ean13 = new Ean13(),
    ) {}

    /**
     * Converts the (normalized, validated) identifier to 13 EAN digits.
     */
    abstract protected function toEan(string $identifier): string;

    /**
     * The heading printed above the symbol, e.g. "ISBN 978-1-…".
     */
    abstract protected function heading(string $identifier, string $ean): string;

    public function quietZone(): int
    {
        return 11;
    }

    protected function normalize(string $data): string
    {
        [$main, $addOn] = AbstractEan::splitAddOn(preg_replace('/^\s*' . preg_quote($this->name(), '/') . '[:\s]*/i', '', $data) ?? $data);
        $main = strtoupper(str_replace([' ', '-'], '', $main));

        return $addOn === null ? $main : $main . '+' . $addOn;
    }

    protected function doEncode(string $data): LinearSymbol
    {
        [$main, $addOn] = array_pad(explode('+', $data, 2), 2, null);
        $ean = $this->toEan((string) $main);

        $symbol = $this->ean13->encodeLinear($addOn === null ? $ean : $ean . '+' . $addOn);

        return $symbol->withSymbology($this->name())->withHeading($this->heading((string) $main, $ean));
    }

    /**
     * Splits validated data into the identifier and add-on parts for validation.
     *
     * @return array{string, string|null}
     */
    protected function parts(string $data): array
    {
        [$main, $addOn] = array_pad(explode('+', $data, 2), 2, null);

        return [(string) $main, $addOn];
    }

    protected function validateAddOn(?string $addOn): void
    {
        if ($addOn !== null && (!ctype_digit($addOn) || !in_array(strlen($addOn), [2, 5], true))) {
            throw InvalidDataException::invalidLength($this->name() . ' add-on', '2 or 5 digits', strlen($addOn));
        }
    }
}
