<?php

declare(strict_types=1);

namespace PhpBarcode\Payload;

/**
 * A compact contact card (NTT DoCoMo MECARD), smaller than a vCard.
 */
final class MeCard extends AbstractPayload
{
    public function __construct(
        private readonly string $name,
        private readonly ?string $phone = null,
        private readonly ?string $email = null,
        private readonly ?string $url = null,
        private readonly ?string $address = null,
        private readonly ?string $note = null,
    ) {}

    public function toString(): string
    {
        $fields = ['N' => $this->name, 'TEL' => $this->phone, 'EMAIL' => $this->email, 'URL' => $this->url, 'ADR' => $this->address, 'NOTE' => $this->note];
        $out = 'MECARD:';
        foreach ($fields as $key => $value) {
            if ($value !== null && $value !== '') {
                $out .= $key . ':' . self::escapeSpecial($value) . ';';
            }
        }

        return $out . ';';
    }
}
