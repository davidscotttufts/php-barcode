<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\LinearSymbol;
use PhpBarcode\Symbol\Symbol;

/**
 * Template for symbologies: normalize, then validate, then encode.
 *
 * Subclasses implement validate() and doEncode(), and may override
 * normalize() (for example to upper-case input) and quietZone().
 */
abstract class AbstractSymbology implements SymbologyInterface
{
    final public function encode(string $data): Symbol
    {
        $normalized = $this->normalize($data);

        if ($normalized === '') {
            throw InvalidDataException::empty($this->name());
        }

        $this->validate($normalized);

        return $this->doEncode($normalized);
    }

    final public function supports(string $data): bool
    {
        try {
            $this->encode($data);

            return true;
        } catch (InvalidDataException) {
            return false;
        }
    }

    /**
     * Recommended quiet zone on each side, in modules.
     */
    public function quietZone(): int
    {
        return 10;
    }

    protected function normalize(string $data): string
    {
        return $data;
    }

    /**
     * @throws InvalidDataException
     */
    abstract protected function validate(string $data): void;

    abstract protected function doEncode(string $data): Symbol;

    /**
     * Throws for the first character of $data that is not in $alphabet.
     *
     * @throws InvalidDataException
     */
    protected function assertAlphabet(string $data, string $alphabet): void
    {
        $valid = strspn($data, $alphabet);

        if ($valid !== strlen($data)) {
            throw InvalidDataException::invalidCharacter($this->name(), $data[$valid], $valid);
        }
    }

    /**
     * Wraps a width pattern in a LinearSymbol carrying this symbology's metadata.
     */
    protected function fromWidths(string $pattern, string $text): LinearSymbol
    {
        return LinearSymbol::fromWidthString($pattern, $text, $this->name(), $this->quietZone());
    }
}
