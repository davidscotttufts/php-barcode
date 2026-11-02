<?php

declare(strict_types=1);

namespace PhpBarcode\Checksum;

/**
 * Computes the check character for a payload.
 */
interface ChecksumInterface
{
    public function calculate(string $payload): string;
}
