<?php

declare(strict_types=1);

namespace PhpBarcode\Payload;

use PhpBarcode\Exception\InvalidDataException;

/**
 * A SEPA credit transfer ("EPC QR code" / GiroCode), per EPC069-12 version 002.
 *
 * Banking apps across the eurozone prefill a transfer from it. Use QR error
 * correction level M, as the standard requires.
 */
final class EpcPayment extends AbstractPayload
{
    public function __construct(
        private readonly string $beneficiaryName,
        private readonly string $iban,
        private readonly ?float $amountEur = null,
        private readonly ?string $bic = null,
        private readonly ?string $remittanceText = null,
        private readonly ?string $creditorReference = null,
        private readonly ?string $purpose = null,
    ) {
        if ($beneficiaryName === '' || self::length($beneficiaryName) > 70) {
            throw new InvalidDataException('The beneficiary name must be 1–70 characters.');
        }
        if (!self::isValidIban($iban)) {
            throw new InvalidDataException(sprintf('"%s" is not a valid IBAN.', $iban));
        }
        if ($amountEur !== null && ($amountEur < 0.01 || $amountEur > 999999999.99)) {
            throw new InvalidDataException('The amount must be between 0.01 and 999999999.99 EUR.');
        }
        if ($remittanceText !== null && $creditorReference !== null) {
            throw new InvalidDataException('Use either a structured creditor reference or remittance text, not both.');
        }
        if ($remittanceText !== null && self::length($remittanceText) > 140) {
            throw new InvalidDataException('Remittance text is limited to 140 characters.');
        }
    }

    public function toString(): string
    {
        return implode("\n", [
            'BCD',
            '002',
            '1', // UTF-8
            'SCT',
            $this->bic ?? '',
            $this->beneficiaryName,
            strtoupper(str_replace(' ', '', $this->iban)),
            $this->amountEur === null ? '' : 'EUR' . number_format($this->amountEur, 2, '.', ''),
            $this->purpose ?? '',
            $this->creditorReference ?? '',
            $this->remittanceText ?? '',
        ]);
    }

    private static function length(string $value): int
    {
        return (int) preg_match_all('/./su', $value);
    }

    private static function isValidIban(string $iban): bool
    {
        $iban = strtoupper(str_replace(' ', '', $iban));
        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return false;
        }

        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_digit($char) ? $char : (string) (ord($char) - 55);
        }

        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder . $chunk) % 97;
        }

        return $remainder === 1;
    }
}
