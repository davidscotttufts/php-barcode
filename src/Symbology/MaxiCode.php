<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Encoding\GaloisField;
use PhpBarcode\Encoding\ReedSolomon;
use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\HexagonSymbol;
use PhpBarcode\Symbology\MaxiCode\Tables;
use PhpBarcode\Symbology\MaxiCode\TextEncoder;

/**
 * MaxiCode (ISO/IEC 16023), the fixed-size hexagonal code used by UPS on
 * parcel labels: 33 rows × 30 columns around a circular bullseye.
 *
 * Modes:
 * - 2: Structured Carrier Message with a numeric postal code (US ZIP, up to 9 digits).
 * - 3: Structured Carrier Message with an alphanumeric postal code (up to 6 characters).
 * - 4: Standard symbol (default without a postal code), up to 93 codewords.
 * - 5: Full error correction, up to 77 codewords.
 * - 6: Reader programming.
 *
 * For modes 2 and 3 the postal code, ISO 3166 country code and service
 * class form the primary message, and the data is the secondary message,
 * usually starting with the "[)>" RS "01" GS "96" header used by UPS.
 *
 * Encoding after Zint (BSD 3-Clause), with the minimal code-set search by
 * Bue Jensen from BWIPP (MIT).
 */
final class MaxiCode extends AbstractSymbology
{
    public const ECI_UTF8 = 26;

    private const ROWS = 33;
    private const COLUMNS = 30;

    /** Orientation marks and fillers that are always dark: [row, column]. */
    private const FIXED = [[0, 28], [0, 29], [9, 10], [9, 11], [10, 11], [15, 7], [16, 8], [16, 20], [17, 20], [22, 10], [23, 10], [22, 17], [23, 17]];

    private const SCM_HEADER = "[)>\x1E01\x1D";

    private readonly int $mode;

    /**
     * @param int|null    $mode           2-6; null chooses 2 or 3 when a postal code is given, otherwise 4.
     * @param string|null $postalCode     Primary message postal code (modes 2 and 3).
     * @param int|null    $countryCode    ISO 3166 numeric country code, e.g. 840 for the USA (modes 2 and 3).
     * @param int|null    $serviceClass   Carrier service class, 0-999 (modes 2 and 3).
     * @param int|null    $eci            ECI to declare; by default ECI 26 (UTF-8) is declared for non-ASCII UTF-8 input.
     * @param int         $appendIndex    Structured append: this symbol's position, 1-8 (0 = not appended).
     * @param int         $appendCount    Structured append: total symbols, 2-8.
     */
    public function __construct(
        ?int $mode = null,
        private readonly ?string $postalCode = null,
        private readonly ?int $countryCode = null,
        private readonly ?int $serviceClass = null,
        private readonly ?int $eci = null,
        private readonly int $appendIndex = 0,
        private readonly int $appendCount = 0,
    ) {
        $mode ??= $postalCode === null ? 4 : (preg_match('/^\d{1,9}$/', $postalCode) === 1 ? 2 : 3);

        if ($mode < 2 || $mode > 6) {
            throw new \InvalidArgumentException('MaxiCode mode must be between 2 and 6.');
        }
        if ($mode <= 3) {
            if ($postalCode === null || $countryCode === null || $serviceClass === null) {
                throw new \InvalidArgumentException('MaxiCode modes 2 and 3 need a postal code, country code and service class.');
            }
            if ($countryCode < 0 || $countryCode > 999 || $serviceClass < 0 || $serviceClass > 999) {
                throw new \InvalidArgumentException('MaxiCode country codes and service classes are 0-999.');
            }
            if ($mode === 2 && preg_match('/^\d{1,9}$/', $postalCode) !== 1) {
                throw new \InvalidArgumentException('MaxiCode mode 2 needs a numeric postal code of up to 9 digits.');
            }
            if ($mode === 3 && preg_match('/^[A-Za-z0-9 ]{1,6}/', $postalCode) !== 1) {
                throw new \InvalidArgumentException('MaxiCode mode 3 needs an alphanumeric postal code.');
            }
        } elseif ($postalCode !== null) {
            throw new \InvalidArgumentException(sprintf('MaxiCode mode %d has no primary message; omit the postal code.', $mode));
        }
        if ($eci !== null && ($eci < 0 || $eci > 999999)) {
            throw new \InvalidArgumentException('An ECI designator must be between 0 and 999999.');
        }
        if ($appendCount !== 0 || $appendIndex !== 0) {
            if ($appendCount < 2 || $appendCount > 8 || $appendIndex < 1 || $appendIndex > $appendCount) {
                throw new \InvalidArgumentException('MaxiCode structured append needs a count of 2-8 and an index from 1 to the count.');
            }
        }

        $this->mode = $mode;
    }

    public function name(): string
    {
        return 'MaxiCode';
    }

    public function quietZone(): int
    {
        return 1;
    }

    protected function validate(string $data): void
    {
        // Every byte value has a MaxiCode representation; only length is limited, and is checked when encoding.
    }

    protected function doEncode(string $data): HexagonSymbol
    {
        $codewords = array_fill(0, 144, 0);
        $hasPrimary = $this->mode <= 3;

        if ($hasPrimary) {
            $this->primaryMessage($codewords);
        } else {
            $codewords[0] = $this->mode;
        }

        $eci = $this->eciFor($data);
        if ($eci !== null && $hasPrimary && str_starts_with($data, self::SCM_HEADER)) {
            throw new InvalidDataException('MaxiCode cannot combine an ECI with a "[)>" structured carrier message header.');
        }

        $prefix = [];
        if ($this->appendCount > 0) {
            $prefix = [33, ($this->appendCount - 1) | (($this->appendIndex - 1) << 3)];
        }
        if ($eci !== null) {
            $prefix = [...$prefix, ...self::eciCodewords($eci)];
        }

        // Modes 4-6 carry the first nine data codewords in the primary message, so
        // encode from position 11 and move those nine into place afterwards.
        $start = $hasPrimary ? 20 : 11;
        $end = $this->mode === 5 ? 88 : 104;

        try {
            (new TextEncoder())->encode($codewords, $start, $end, $data, $prefix);
        } catch (\OverflowException $e) {
            throw new InvalidDataException(sprintf('%s mode %d: %s', $this->name(), $this->mode, $e->getMessage()), 0, $e);
        }

        if (!$hasPrimary) {
            for ($i = 0; $i < 9; $i++) {
                $codewords[1 + $i] = $codewords[11 + $i];
            }
        }

        $this->addErrorCorrection($codewords);

        return new HexagonSymbol(
            $this->draw($codewords),
            self::printable($data),
            $this->name(),
            finderRow: 16,
            finderColumn: 14,
            rings: self::rings(),
            quietZone: $this->quietZone(),
        );
    }

    /**
     * @param array<int, int> $codewords
     */
    private function primaryMessage(array &$codewords): void
    {
        $country = (int) $this->countryCode;
        $service = (int) $this->serviceClass;
        $postal = (string) $this->postalCode;

        if ($this->mode === 2) {
            // A US ZIP code without its +4 is padded with zeros (ISO/IEC 16023 Annex B).
            if ($country === 840 && strlen($postal) === 5) {
                $postal .= '0000';
            }
            $number = (int) $postal;
            $length = strlen($postal);

            $codewords[0] = (($number & 0x03) << 4) | 2;
            $codewords[1] = ($number & 0xFC) >> 2;
            $codewords[2] = ($number & 0x3F00) >> 8;
            $codewords[3] = ($number & 0xFC000) >> 14;
            $codewords[4] = ($number & 0x3F00000) >> 20;
            $codewords[5] = (($number & 0x3C000000) >> 26) | (($length & 0x03) << 4);
            $codewords[6] = (($length & 0x3C) >> 2) | (($country & 0x03) << 4);
        } else {
            $postal = str_pad(substr(strtoupper($postal), 0, 6), 6);
            $values = [];
            foreach (str_split($postal) as $position => $character) {
                $byte = ord($character);
                if ($byte < 32 || (Tables::CODE_SET[$byte] & 0x01) === 0) {
                    throw new InvalidDataException(sprintf('MaxiCode mode 3 postal codes cannot contain "%s" (position %d).', $character, $position));
                }
                $values[] = Tables::SYMBOL_CHAR[$byte];
            }

            $codewords[0] = (($values[5] & 0x03) << 4) | 3;
            $codewords[1] = (($values[4] & 0x03) << 4) | (($values[5] & 0x3C) >> 2);
            $codewords[2] = (($values[3] & 0x03) << 4) | (($values[4] & 0x3C) >> 2);
            $codewords[3] = (($values[2] & 0x03) << 4) | (($values[3] & 0x3C) >> 2);
            $codewords[4] = (($values[1] & 0x03) << 4) | (($values[2] & 0x3C) >> 2);
            $codewords[5] = (($values[0] & 0x03) << 4) | (($values[1] & 0x3C) >> 2);
            $codewords[6] = (($values[0] & 0x3C) >> 2) | (($country & 0x03) << 4);
        }

        $codewords[7] = ($country & 0xFC) >> 2;
        $codewords[8] = (($country & 0x300) >> 8) | (($service & 0x0F) << 2);
        $codewords[9] = ($service & 0x3F0) >> 4;
    }

    /**
     * Primary: 10 data + 10 check words. Secondary: interleaved even/odd halves,
     * 84 + 40 (standard) or 68 + 56 (enhanced, mode 5).
     *
     * @param array<int, int> $codewords
     */
    private function addErrorCorrection(array &$codewords): void
    {
        $rs = new ReedSolomon(GaloisField::aztec(6)); // GF(64), x^6 + x + 1, generator base 1

        foreach ($rs->encode(array_slice($codewords, 0, 10), 10) as $j => $word) {
            $codewords[10 + $j] = $word;
        }

        [$dataLength, $eccLength] = $this->mode === 5 ? [68, 28] : [84, 20];
        for ($parity = 0; $parity < 2; $parity++) {
            $data = [];
            for ($j = $parity; $j < $dataLength; $j += 2) {
                $data[] = $codewords[20 + $j];
            }
            foreach ($rs->encode($data, $eccLength) as $j => $word) {
                $codewords[20 + $dataLength + 2 * $j + $parity] = $word;
            }
        }
    }

    /**
     * @param array<int, int> $codewords
     *
     * @return list<string>
     */
    private function draw(array $codewords): array
    {
        $grid = array_fill(0, self::ROWS, str_repeat('0', self::COLUMNS));

        for ($row = 0; $row < self::ROWS; $row++) {
            for ($column = 0; $column < self::COLUMNS; $column++) {
                $sequence = Tables::GRID[$row * self::COLUMNS + $column];
                if ($sequence === 0) {
                    continue;
                }
                // Module n carries bit (n-1) % 6 (most significant first) of codeword (n-1) / 6.
                $index = $sequence - 1;
                if ((($codewords[intdiv($index, 6)] >> (5 - $index % 6)) & 1) === 1) {
                    $grid[$row][$column] = '1';
                }
            }
        }

        foreach (self::FIXED as [$row, $column]) {
            $grid[$row][$column] = '1';
        }

        return $grid;
    }

    /**
     * Three dark rings within a 9-module finder: the central light disc is one
     * hexagon tall and the six boundaries are evenly spaced out to 9W.
     *
     * @return list<array{inner: float, outer: float}>
     */
    private static function rings(): array
    {
        $increment = (9 - HexagonSymbol::HEX_HEIGHT) / 5;
        $rings = [];
        for ($i = 0; $i < 3; $i++) {
            $rings[] = [
                'inner' => (HexagonSymbol::HEX_HEIGHT + 2 * $i * $increment) / 2,
                'outer' => (HexagonSymbol::HEX_HEIGHT + (2 * $i + 1) * $increment) / 2,
            ];
        }

        return $rings;
    }

    /**
     * @return list<int>
     */
    private static function eciCodewords(int $eci): array
    {
        return match (true) {
            $eci <= 31 => [27, $eci],
            $eci <= 1023 => [27, 0x20 | ($eci >> 6), $eci & 0x3F],
            $eci <= 32767 => [27, 0x30 | ($eci >> 12), ($eci >> 6) & 0x3F, $eci & 0x3F],
            default => [27, 0x38 | ($eci >> 18), ($eci >> 12) & 0x3F, ($eci >> 6) & 0x3F, $eci & 0x3F],
        };
    }

    private function eciFor(string $data): ?int
    {
        if ($this->eci !== null) {
            return $this->eci;
        }

        return preg_match('/[\x80-\xFF]/', $data) === 1 && preg_match('//u', $data) === 1 ? self::ECI_UTF8 : null;
    }

    private static function printable(string $data): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $data);
    }
}
