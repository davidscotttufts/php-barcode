<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\LinearSymbol;

/**
 * Base for the EAN-2 and EAN-5 supplemental ("add-on") symbols.
 *
 * Usually attached to an EAN-13/UPC-A/UPC-E/EAN-8 by appending "+12" or
 * "+12345" to its data, but they can also be encoded on their own.
 */
abstract class AbstractEanAddOn extends AbstractSymbology
{
    abstract protected function digits(): int;

    /**
     * Parity pattern (L/G per digit) for the given add-on value.
     */
    abstract protected function parity(string $data): string;

    public function quietZone(): int
    {
        return 7;
    }

    public function encodeLinear(string $data): LinearSymbol
    {
        $symbol = $this->encode($data);
        \assert($symbol instanceof LinearSymbol);

        return $symbol;
    }

    protected function validate(string $data): void
    {
        $this->assertAlphabet($data, '0123456789');

        if (strlen($data) !== $this->digits()) {
            throw InvalidDataException::invalidLength($this->name(), sprintf('%d digits', $this->digits()), strlen($data));
        }
    }

    protected function doEncode(string $data): LinearSymbol
    {
        $parity = $this->parity($data);
        $characters = array_map(
            static fn(string $digit, int $i): string => $parity[$i] === 'L' ? EanTables::l($digit) : EanTables::g($digit),
            str_split($data),
            array_keys(str_split($data)),
        );

        $bits = '1011' . implode('01', $characters);
        $modules = strlen($bits);

        return new LinearSymbol(
            LinearSymbol::runLengths($bits),
            $data,
            $this->name(),
            $this->quietZone(),
            rightQuietZone: 5,
            textSegments: [['text' => $data, 'x' => $modules / 2, 'anchor' => 'middle', 'position' => 'addon']],
            addOnStart: 0,
        );
    }
}
