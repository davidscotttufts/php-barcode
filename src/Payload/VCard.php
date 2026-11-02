<?php

declare(strict_types=1);

namespace PhpBarcode\Payload;

/**
 * A vCard 3.0 contact, the most widely supported format for "add contact" codes.
 */
final class VCard extends AbstractPayload
{
    /**
     * @param list<string> $phones
     * @param list<string> $emails
     */
    public function __construct(
        private readonly string $firstName,
        private readonly string $lastName = '',
        private readonly ?string $organization = null,
        private readonly ?string $title = null,
        private readonly array $phones = [],
        private readonly array $emails = [],
        private readonly ?string $url = null,
        private readonly ?string $street = null,
        private readonly ?string $city = null,
        private readonly ?string $region = null,
        private readonly ?string $postalCode = null,
        private readonly ?string $country = null,
        private readonly ?string $note = null,
    ) {
        if (trim($firstName . $lastName) === '') {
            throw new \InvalidArgumentException('A vCard needs a name.');
        }
    }

    public function toString(): string
    {
        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'N:' . self::escape($this->lastName) . ';' . self::escape($this->firstName) . ';;;',
            'FN:' . self::escape(trim($this->firstName . ' ' . $this->lastName)),
        ];
        if ($this->organization !== null) {
            $lines[] = 'ORG:' . self::escape($this->organization);
        }
        if ($this->title !== null) {
            $lines[] = 'TITLE:' . self::escape($this->title);
        }
        foreach ($this->phones as $phone) {
            $lines[] = 'TEL:' . self::escape($phone);
        }
        foreach ($this->emails as $email) {
            $lines[] = 'EMAIL:' . self::escape($email);
        }
        if ($this->url !== null) {
            $lines[] = 'URL:' . self::escape($this->url);
        }
        $address = [$this->street, $this->city, $this->region, $this->postalCode, $this->country];
        if (array_filter($address, static fn(?string $part): bool => $part !== null && $part !== '') !== []) {
            $lines[] = 'ADR:;;' . implode(';', array_map(static fn(?string $part): string => self::escape($part ?? ''), $address));
        }
        if ($this->note !== null) {
            $lines[] = 'NOTE:' . self::escape($this->note);
        }
        $lines[] = 'END:VCARD';

        return implode("\r\n", $lines);
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', "\r\n", "\n", ',', ';'], ['\\\\', '\\n', '\\n', '\\,', '\;'], $value);
    }
}
