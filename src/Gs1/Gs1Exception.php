<?php

declare(strict_types=1);

namespace PhpBarcode\Gs1;

use PhpBarcode\Exception\BarcodeException;

/**
 * Thrown when GS1 element string data is malformed or violates the GS1 rules.
 *
 * The message always names the Application Identifier and, where it applies,
 * the 1-based character position within that AI's value.
 */
final class Gs1Exception extends \InvalidArgumentException implements BarcodeException
{
    public function __construct(
        string $message,
        public readonly ?string $ai = null,
        public readonly ?int $position = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Builds "AI (01): invalid check digit at position 14, expected 2".
     */
    public static function forValue(string $ai, string $problem, ?int $position = null, ?string $hint = null): self
    {
        $message = sprintf('AI (%s): %s', $ai, $problem)
            . ($position === null ? '' : sprintf(' at position %d', $position))
            . ($hint === null ? '' : ', ' . $hint);

        return new self($message, $ai, $position);
    }
}
