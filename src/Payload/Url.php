<?php

declare(strict_types=1);

namespace PhpBarcode\Payload;

final class Url extends AbstractPayload
{
    public function __construct(
        private readonly string $url,
    ) {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid URL.', $url));
        }
    }

    public function toString(): string
    {
        return $this->url;
    }
}
