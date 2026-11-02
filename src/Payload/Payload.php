<?php

declare(strict_types=1);

namespace PhpBarcode\Payload;

/**
 * Structured content for 2D codes, formatted the way phone camera apps expect.
 *
 *     Barcode::qrCode(new WiFi('Cafe', 'secret'))
 */
interface Payload extends \Stringable
{
    public function toString(): string;
}
