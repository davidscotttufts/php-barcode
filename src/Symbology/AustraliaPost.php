<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Encoding\GaloisField;
use PhpBarcode\Encoding\ReedSolomon;
use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\HeightModulatedSymbol;

/**
 * Australia Post 4-State Customer Barcode.
 *
 * Input is an 8-digit Delivery Point Identifier (DPID), optionally followed by
 * customer information. Without an explicit format the FCC is chosen from the
 * input: Standard Customer (11) for a bare DPID, else Customer Barcode 2 (59)
 * or 3 (62) depending on length. Customer information that is all digits uses
 * the compact N table; anything else uses the C table (A-Z, a-z, 0-9, space, #).
 * Four Reed–Solomon symbols over GF(64) protect the data.
 *
 * Per "Australia Post Customer Barcoding Technical Specifications"; tables
 * cross-checked with Zint (BSD-3-Clause).
 */
final class AustraliaPost extends AbstractFourStateSymbology
{
    /** Two bars per digit, for the FCC, DPID and numeric customer information. */
    private const N_TABLE = ['FF', 'FA', 'FD', 'AF', 'AA', 'AD', 'DF', 'DA', 'DD', 'TF'];

    private const C_ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz #';

    /** Three bars per character of C_ALPHABET. */
    private const C_TABLE = [
        'DDD', 'TFF', 'TFA', 'TFD', 'TAF', 'TAA', 'TAD', 'TDF', 'TDA', 'TDD', 'FFF', 'FFA', 'FFD', 'FAF', 'FAA', 'FAD',
        'FDF', 'FDA', 'FDD', 'AFF', 'AFA', 'AFD', 'AAF', 'AAA', 'AAD', 'ADF', 'ADA', 'ADD', 'DFF', 'DFA', 'DFD', 'DAF',
        'DAA', 'DAD', 'DDF', 'DDA', 'FDT', 'FTF', 'FTA', 'FTD', 'FTT', 'AFT', 'AAT', 'ADT', 'ATF', 'ATA', 'ATD', 'ATT',
        'DFT', 'DAT', 'DDT', 'DTF', 'DTA', 'DTD', 'DTT', 'TFT', 'TAT', 'TDT', 'TTF', 'TTA', 'TTD', 'TTT', 'FFT', 'FAT',
    ];

    /** Bar states as base-4 digits, for the Reed–Solomon conversion. */
    private const BAR_VALUES = ['F' => 0, 'A' => 1, 'D' => 2, 'T' => 3];

    private const FRAME = 'AT';

    private const NULL_DPID = '00000000';

    public function __construct(
        private readonly ?AustraliaPostFormat $format = null,
    ) {}

    public function name(): string
    {
        return 'Australia Post';
    }

    protected function normalize(string $data): string
    {
        // Pad a short DPID: "1234" → "00001234".
        return strlen($data) < 8 && ctype_digit($data) ? str_pad($data, 8, '0', STR_PAD_LEFT) : $data;
    }

    protected function validate(string $data): void
    {
        $dpid = substr($data, 0, 8);
        $this->assertAlphabet($dpid, '0123456789');

        $information = (string) substr($data, 8);
        if (strspn($information, self::C_ALPHABET) !== strlen($information)) {
            $position = strspn($information, self::C_ALPHABET);
            throw InvalidDataException::invalidCharacter($this->name(), $information[$position], 8 + $position);
        }

        $format = $this->formatFor($data);
        if ($this->informationBars($information) > $format->customerInformationBars()) {
            throw InvalidDataException::invalidLength(
                $this->name(),
                sprintf('customer information fitting in %d bars for FCC %s', $format->customerInformationBars(), $format->value),
                strlen($information),
            );
        }
    }

    protected function doEncode(string $data): HeightModulatedSymbol
    {
        $dpid = substr($data, 0, 8);
        $information = (string) substr($data, 8);
        $format = $this->formatFor($data);
        $fcc = $dpid === self::NULL_DPID && $this->format === null ? '00' : $format->value;

        $bars = self::FRAME . $this->numeric($fcc . $dpid);
        $bars .= ctype_digit($information) || $information === '' ? $this->numeric($information) : $this->characters($information);
        $bars = str_pad($bars, $format->dataBars(), 'T');

        $bars .= $this->errorCorrection(substr($bars, 2));

        return $this->fromStates($bars . self::FRAME, $data);
    }

    private function formatFor(string $data): AustraliaPostFormat
    {
        if ($this->format !== null) {
            return $this->format;
        }

        $information = (string) substr($data, 8);
        $length = strlen($data);

        return match (true) {
            $information === '' => AustraliaPostFormat::StandardCustomer,
            $length > 16 || ($length > 13 && !ctype_digit($information)) => AustraliaPostFormat::Customer3,
            default => AustraliaPostFormat::Customer2,
        };
    }

    private function informationBars(string $information): int
    {
        return ctype_digit($information) || $information === '' ? 2 * strlen($information) : 3 * strlen($information);
    }

    private function numeric(string $digits): string
    {
        return implode('', array_map(static fn(string $digit): string => self::N_TABLE[(int) $digit], str_split($digits)));
    }

    private function characters(string $text): string
    {
        return implode('', array_map(
            static fn(string $character): string => self::C_TABLE[(int) strpos(self::C_ALPHABET, $character)],
            str_split($text),
        ));
    }

    /**
     * Four Reed–Solomon parity symbols over GF(64) (x^6 + x + 1), each written as three bars.
     */
    private function errorCorrection(string $bars): string
    {
        $symbols = array_map(
            static fn(string $triple): int => self::BAR_VALUES[$triple[0]] << 4 | self::BAR_VALUES[$triple[1]] << 2 | self::BAR_VALUES[$triple[2]],
            str_split($bars, 3),
        );

        $parity = (new ReedSolomon(new GaloisField(64, 0x43, 1)))->encode($symbols, 4);
        $states = array_flip(self::BAR_VALUES);

        return implode('', array_map(
            static fn(int $value): string => $states[$value >> 4] . $states[($value >> 2) & 3] . $states[$value & 3],
            $parity,
        ));
    }
}
