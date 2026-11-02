<?php

declare(strict_types=1);

namespace PhpBarcode\Payload;

final class Sms extends AbstractPayload
{
    public function __construct(
        private readonly string $number,
        private readonly ?string $message = null,
    ) {
        if (preg_match('/^\+?[0-9 ()\-.]{3,}$/', $number) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid phone number.', $number));
        }
    }

    public function toString(): string
    {
        $number = (string) preg_replace('/[^0-9+]/', '', $this->number);

        return $this->message === null ? 'sms:' . $number : 'SMSTO:' . $number . ':' . $this->message;
    }
}
