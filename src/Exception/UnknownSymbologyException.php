<?php

declare(strict_types=1);

namespace PhpBarcode\Exception;

final class UnknownSymbologyException extends \InvalidArgumentException implements BarcodeException
{
    /**
     * @param list<string> $known
     */
    public static function forName(string $name, array $known): self
    {
        return new self(sprintf(
            'Unknown barcode type "%s". Known types: %s.',
            $name,
            implode(', ', $known),
        ));
    }
}
