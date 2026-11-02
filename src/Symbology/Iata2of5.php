<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

/**
 * IATA 2 of 5, the air-cargo variant of Industrial 2 of 5 with shorter start/stop patterns.
 */
final class Iata2of5 extends AbstractDiscrete2of5
{
    public function name(): string
    {
        return 'IATA 2 of 5';
    }

    protected function start(): string
    {
        return 'nnnn';
    }

    protected function stop(): string
    {
        return 'wnn';
    }
}
