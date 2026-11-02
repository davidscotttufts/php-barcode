<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Payload;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Payload\CalendarEvent;
use PhpBarcode\Payload\Email;
use PhpBarcode\Payload\EpcPayment;
use PhpBarcode\Payload\Geo;
use PhpBarcode\Payload\MeCard;
use PhpBarcode\Payload\VCard;
use PhpBarcode\Payload\WiFi;
use PHPUnit\Framework\TestCase;

final class PayloadTest extends TestCase
{
    public function testWiFiEscapesSpecialCharacters(): void
    {
        self::assertSame('WIFI:T:WPA;S:My\;Net;P:pa\:ss;H:true;;', (string) new WiFi('My;Net', 'pa:ss', hidden: true));
        self::assertSame('WIFI:T:nopass;S:Open;;', (new WiFi('Open', security: 'nopass'))->toString());
    }

    public function testEpcPaymentFollowsEpc069(): void
    {
        $payload = new EpcPayment('Red Cross', 'BE72 0000 0000 1616', 12.5, 'BPOTBEB1', 'Donation');

        self::assertSame("BCD\n002\n1\nSCT\nBPOTBEB1\nRed Cross\nBE72000000001616\nEUR12.50\n\n\nDonation", (string) $payload);
    }

    public function testEpcRejectsBadIban(): void
    {
        $this->expectException(InvalidDataException::class);
        new EpcPayment('X', 'BE72 0000 0000 1617');
    }

    public function testContactsAndOthers(): void
    {
        self::assertStringContainsString("FN:Ada Lovelace\r\n", (string) new VCard('Ada', 'Lovelace', phones: ['+44 20 1234']));
        self::assertSame('MECARD:N:Lovelace\,Ada;TEL:123;;', (string) new MeCard('Lovelace,Ada', '123'));
        self::assertSame('mailto:a@example.com?subject=Hi%20there', (string) new Email('a@example.com', 'Hi there'));
        self::assertSame('geo:51.5007,-0.1246', (string) new Geo(51.5007, -0.1246));
        self::assertStringContainsString(
            'DTSTART:20261102T090000Z',
            (string) new CalendarEvent('Launch', new \DateTimeImmutable('2026-11-02 10:00', new \DateTimeZone('Europe/Paris')), new \DateTimeImmutable('2026-11-02 11:00', new \DateTimeZone('Europe/Paris'))),
        );
    }
}
