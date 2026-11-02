<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Checksum\Mod10;
use PhpBarcode\Exception\InvalidDataException;

/**
 * UPC-E, the zero-suppressed 6-digit form of UPC-A for small packages.
 *
 * Accepts 6 digits (number system 0 assumed), 7 digits (number system 0 or 1
 * first), 8 digits (with check digit, verified), or an 11/12-digit UPC-A
 * number that can be zero-suppressed. The check digit is that of the
 * equivalent UPC-A number and is encoded through the parity pattern.
 */
final class UpcE extends AbstractEan
{
    /** Parity of the six digits for number system 0, indexed by check digit; inverted for number system 1. */
    private const PARITY = [
        'GGGLLL', 'GGLGLL', 'GGLLGL', 'GGLLLG', 'GLGGLL',
        'GLLGGL', 'GLLLGG', 'GLGLGL', 'GLGLLG', 'GLLGLG',
    ];

    public function name(): string
    {
        return 'UPC-E';
    }

    public function quietZone(): int
    {
        return 9;
    }

    protected function length(): int
    {
        return 8;
    }

    protected function acceptedLengths(): array
    {
        return [6, 7, 8, 11, 12];
    }

    /**
     * Expands a 7-digit UPC-E number (number system + 6 digits) to the 11-digit UPC-A payload.
     */
    public static function expand(string $upcE): string
    {
        [$ns, $d] = [$upcE[0], substr($upcE, 1, 6)];

        return $ns . match ($d[5]) {
            '0', '1', '2' => $d[0] . $d[1] . $d[5] . '0000' . $d[2] . $d[3] . $d[4],
            '3' => $d[0] . $d[1] . $d[2] . '00000' . $d[3] . $d[4],
            '4' => $d[0] . $d[1] . $d[2] . $d[3] . '00000' . $d[4],
            default => $d[0] . $d[1] . $d[2] . $d[3] . $d[4] . '0000' . $d[5],
        };
    }

    /**
     * Zero-suppresses an 11-digit UPC-A payload (no check digit) to 7 digits, or null if impossible.
     */
    public static function compress(string $upcA): ?string
    {
        if (strlen($upcA) !== 11 || !ctype_digit($upcA) || ($upcA[0] !== '0' && $upcA[0] !== '1')) {
            return null;
        }

        [$ns, $m, $p] = [$upcA[0], substr($upcA, 1, 5), substr($upcA, 6, 5)];

        $candidate = match (true) {
            in_array(substr($m, 2), ['000', '100', '200'], true) && str_starts_with($p, '00') => $m[0] . $m[1] . $p[2] . $p[3] . $p[4] . $m[2],
            str_ends_with($m, '00') && str_starts_with($p, '000') => $m[0] . $m[1] . $m[2] . $p[3] . $p[4] . '3',
            str_ends_with($m, '0') && str_starts_with($p, '0000') => $m[0] . $m[1] . $m[2] . $m[3] . $p[4] . '4',
            str_starts_with($p, '0000') && $p[4] >= '5' => $m . $p[4],
            default => null,
        };

        return $candidate !== null && self::expand($ns . $candidate) === $upcA ? $ns . $candidate : null;
    }

    protected function validateMain(string $data): void
    {
        $this->assertAlphabet($data, '0123456789');

        $length = strlen($data);
        if (!in_array($length, $this->acceptedLengths(), true)) {
            throw InvalidDataException::invalidLength($this->name(), '6, 7 or 8 digits (or a compressible 11/12-digit UPC-A)', $length);
        }

        if ($length >= 11) {
            if ($length === 12) {
                $expected = (new Mod10())->calculate(substr($data, 0, 11));
                if ($expected !== $data[11]) {
                    throw InvalidDataException::invalidCheckDigit($this->name(), $expected, $data[11]);
                }
            }
            if (self::compress(substr($data, 0, 11)) === null) {
                throw new InvalidDataException(sprintf('UPC-A number %s cannot be zero-suppressed to UPC-E.', $data));
            }

            return;
        }

        if ($length >= 7 && $data[0] !== '0' && $data[0] !== '1') {
            throw new InvalidDataException(sprintf('UPC-E number system must be 0 or 1, got "%s".', $data[0]));
        }

        if ($length === 8) {
            $expected = (new Mod10())->calculate(self::expand(substr($data, 0, 7)));
            if ($expected !== $data[7]) {
                throw InvalidDataException::invalidCheckDigit($this->name(), $expected, $data[7]);
            }
        }
    }

    protected function complete(string $data): string
    {
        $seven = match (strlen($data)) {
            6 => '0' . $data,
            11, 12 => (string) self::compress(substr($data, 0, 11)),
            default => substr($data, 0, 7),
        };

        return $seven . (new Mod10())->calculate(self::expand($seven));
    }

    protected function bitmap(string $digits): string
    {
        $parity = self::PARITY[(int) $digits[7]];
        if ($digits[0] === '1') {
            $parity = strtr($parity, 'LG', 'GL');
        }

        return EanTables::GUARD . EanTables::withParity(substr($digits, 1, 6), $parity) . '010101';
    }

    protected function guards(): array
    {
        return [[0, 3], [45, 51]];
    }

    protected function textSegments(string $digits): array
    {
        return [
            ['text' => $digits[0], 'x' => -2, 'anchor' => 'end'],
            ['text' => substr($digits, 1, 6), 'x' => 24, 'anchor' => 'middle'],
            ['text' => $digits[7], 'x' => 53, 'anchor' => 'start'],
        ];
    }
}
