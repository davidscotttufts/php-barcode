<?php

declare(strict_types=1);

namespace PhpBarcode\Payload;

abstract class AbstractPayload implements Payload
{
    final public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Escapes \ ; , : and " with a backslash, as the WIFI: and MECARD: formats require.
     */
    protected static function escapeSpecial(string $value): string
    {
        return addcslashes($value, '\;,:"');
    }
}
