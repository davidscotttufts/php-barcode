<?php

declare(strict_types=1);

namespace PhpBarcode\Payload;

/**
 * A map location (RFC 5870 geo: URI).
 */
final class Geo extends AbstractPayload
{
    public function __construct(
        private readonly float $latitude,
        private readonly float $longitude,
        private readonly ?float $altitude = null,
    ) {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new \InvalidArgumentException('Latitude must be within ±90 and longitude within ±180.');
        }
    }

    public function toString(): string
    {
        $format = static fn(float $v): string => rtrim(rtrim(number_format($v, 7, '.', ''), '0'), '.');

        return 'geo:' . $format($this->latitude) . ',' . $format($this->longitude)
            . ($this->altitude === null ? '' : ',' . $format($this->altitude));
    }
}
