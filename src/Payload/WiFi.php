<?php

declare(strict_types=1);

namespace PhpBarcode\Payload;

/**
 * Wi-Fi network credentials; scanning joins the network on iOS and Android.
 */
final class WiFi extends AbstractPayload
{
    /**
     * @param 'WPA'|'WEP'|'nopass'|'SAE' $security WPA covers WPA/WPA2; SAE is WPA3-only.
     */
    public function __construct(
        private readonly string $ssid,
        private readonly ?string $password = null,
        private readonly string $security = 'WPA',
        private readonly bool $hidden = false,
    ) {
        if (!in_array($security, ['WPA', 'WEP', 'nopass', 'SAE'], true)) {
            throw new \InvalidArgumentException('Wi-Fi security must be WPA, WEP, SAE or nopass.');
        }
        if ($security !== 'nopass' && ($password === null || $password === '')) {
            throw new \InvalidArgumentException('A password is required unless security is "nopass".');
        }
    }

    public function toString(): string
    {
        $fields = ['T:' . $this->security, 'S:' . self::escapeSpecial($this->ssid)];
        if ($this->security !== 'nopass' && $this->password !== null) {
            $fields[] = 'P:' . self::escapeSpecial($this->password);
        }
        if ($this->hidden) {
            $fields[] = 'H:true';
        }

        return 'WIFI:' . implode(';', $fields) . ';;';
    }
}
