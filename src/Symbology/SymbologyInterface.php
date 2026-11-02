<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\Symbol;

/**
 * A barcode symbology: the rules that turn data into bars and spaces.
 *
 * Implement this (or extend AbstractSymbology) to add a new barcode type;
 * every renderer works with every symbology.
 */
interface SymbologyInterface
{
    /**
     * Human-readable name, e.g. "Code 128".
     */
    public function name(): string;

    /**
     * @throws InvalidDataException when the data cannot be encoded
     */
    public function encode(string $data): Symbol;

    /**
     * Whether the data can be encoded, without throwing.
     */
    public function supports(string $data): bool;
}
