<?php

declare(strict_types=1);

namespace PhpBarcode\Payload;

final class Email extends AbstractPayload
{
    public function __construct(
        private readonly string $to,
        private readonly ?string $subject = null,
        private readonly ?string $body = null,
    ) {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid email address.', $to));
        }
    }

    public function toString(): string
    {
        $query = http_build_query(array_filter(['subject' => $this->subject, 'body' => $this->body], static fn(?string $v): bool => $v !== null), '', '&', PHP_QUERY_RFC3986);

        return 'mailto:' . $this->to . ($query === '' ? '' : '?' . $query);
    }
}
