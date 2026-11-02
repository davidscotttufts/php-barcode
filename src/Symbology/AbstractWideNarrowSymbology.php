<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

/**
 * Base for two-width symbologies (Code 39, Codabar, Interleaved 2 of 5), whose
 * elements are either narrow or wide.
 *
 * Patterns are written with "n" for narrow and "w" for wide. The wide-to-narrow
 * ratio may be 2 or 3; 3 is the most tolerant of printer and scanner error.
 */
abstract class AbstractWideNarrowSymbology extends AbstractSymbology
{
    public function __construct(
        protected readonly int $wideRatio = 3,
    ) {
        if ($wideRatio < 2 || $wideRatio > 3) {
            throw new \InvalidArgumentException('The wide-to-narrow ratio must be 2 or 3.');
        }
    }

    /**
     * Turns an "n"/"w" pattern into a digit width string, e.g. "nnw" → "113".
     */
    protected function expand(string $pattern): string
    {
        return strtr($pattern, ['n' => '1', 'w' => (string) $this->wideRatio]);
    }
}
