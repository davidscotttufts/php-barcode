<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

/**
 * Industrial 2 of 5 (also "Standard 2 of 5"), a numeric code where only the bars carry data.
 */
final class Industrial2of5 extends AbstractDiscrete2of5
{
    public function name(): string
    {
        return 'Industrial 2 of 5';
    }

    protected function start(): string
    {
        return 'wnwnnn';
    }

    protected function stop(): string
    {
        return 'wnnnw';
    }
}
