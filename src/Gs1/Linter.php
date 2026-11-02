<?php

declare(strict_types=1);

namespace PhpBarcode\Gs1;

use PhpBarcode\Checksum\Mod10;

/**
 * Content checks ("linters") named by the GS1 Barcode Syntax Dictionary.
 *
 * Behaviour follows the reference linters published with the GS1 Syntax
 * Dictionary (Apache-2.0, GS1 AISBL). Each check returns null when the value
 * is valid, or [0-based offset, problem, optional hint] when it is not,
 * e.g. [13, 'invalid check digit', 'expected 2'].
 *
 * Coupon linters ("couponcode", "couponposoffer") and Global Company Prefix
 * allocation lookups ("gcppos1", "gcppos2", which need an online GCP table)
 * only perform the structural checks that can be done offline.
 */
final class Linter
{
    /** Prime weights for the check character pair, from the GS1 General Specifications 7.9.5. */
    private const PRIMES = [
        2, 3, 5, 7, 11, 13, 17, 19, 23, 29, 31, 37, 41, 43, 47, 53, 59, 61, 67, 71, 73, 79, 83, 89, 97,
        101, 103, 107, 109, 113, 127, 131, 137, 139, 149, 151, 157, 163, 167, 173, 179, 181, 191, 193,
        197, 199, 211, 223, 227, 229, 233, 239, 241, 251, 257, 263, 269, 271, 277, 281, 283, 293, 307,
        311, 313, 317, 331, 337, 347, 349, 353, 359, 367, 373, 379, 383, 389, 397, 401, 409, 419, 421,
        431, 433, 439, 443, 449, 457, 461, 463, 467, 479, 487, 491, 499, 503, 509,
    ];

    /** @var array<string, array<string, true>>|null */
    private static ?array $lists = null;

    /**
     * @return array{0: int, 1: string, 2?: string}|null
     */
    public static function check(string $linter, string $value): ?array
    {
        return match ($linter) {
            'csum' => self::checkDigit($value),
            'csumalpha' => self::checkCharacterPair($value),
            'gcppos1' => self::companyPrefix($value, 0),
            'gcppos2' => self::companyPrefix($value, 1),
            'yymmdd' => self::date('20' . $value, 2, false, true),
            'yymmd0' => self::date('20' . $value, 2, true, true),
            'yyyymmdd' => self::date($value, 0, false, false),
            'yyyymmd0' => self::date($value, 0, true, false),
            'hhmi' => self::range($value, 0, 2, 23, 'hour') ?? self::range($value, 2, 2, 59, 'minute'),
            'hh' => self::range($value, 0, 2, 23, 'hour'),
            'mi' => self::range($value, 0, 2, 59, 'minute'),
            'ss' => self::range($value, 0, 2, 59, 'second'),
            'iso3166' => self::inList('iso3166', $value, 'is not an ISO 3166 numeric country code'),
            'iso3166999' => $value === '999' ? null : self::inList('iso3166', $value, 'is not an ISO 3166 numeric country code or 999'),
            'iso3166alpha2' => self::inList('iso3166alpha2', $value, 'is not an ISO 3166 alpha-2 country code'),
            'iso4217' => self::inList('iso4217', $value, 'is not an ISO 4217 numeric currency code'),
            'packagetype' => self::inList('packagetype', $value, 'is not a UN/ECE Recommendation 21 package type code'),
            'iso5218' => in_array($value, ['0', '1', '2', '9'], true) ? null : [0, 'is not an ISO/IEC 5218 biological sex code (0, 1, 2 or 9)'],
            'mediatype' => self::mediaType($value),
            'yesno' => $value === '0' || $value === '1' ? null : [0, 'must be 0 (no) or 1 (yes)'],
            'winding' => in_array($value, ['0', '1', '9'], true) ? null : [0, 'winding direction must be 0, 1 or 9'],
            'zero' => $value === '0' ? null : [0, 'must be 0'],
            'nonzero' => trim($value, '0') !== '' ? null : [0, 'must not be zero'],
            'nozeroprefix' => ($value[0] ?? '') === '0' ? [0, 'must not start with 0'] : null,
            'hyphen' => $value === '-' ? null : [0, 'must be a hyphen ("-")'],
            'hasnondigit' => ctype_digit($value) ? [0, 'must contain at least one non-digit'] : null,
            'pieceoftotal' => self::pieceOfTotal($value),
            'posinseqslash' => self::positionInSequence($value),
            'importeridx' => strlen($value) === 1 && strspn($value, CharacterSet::CSET64) === 1 ? null : [0, 'importer index must be one of - 0-9 A-Z _ a-z'],
            'latitude' => (int) $value <= 1_800_000_000 ? null : [0, 'latitude out of range (maximum 1800000000)'],
            'longitude' => (int) $value <= 3_600_000_000 ? null : [0, 'longitude out of range (maximum 3600000000)'],
            'pcenc' => self::percentEncoding($value),
            'iban' => self::iban($value),
            'couponcode', 'couponposoffer' => null,
            default => throw new \LogicException(sprintf('Unknown GS1 linter "%s".', $linter)),
        };
    }

    /**
     * @return array{0: int, 1: string, 2?: string}|null
     */
    private static function checkDigit(string $value): ?array
    {
        if (!ctype_digit($value) || strlen($value) < 2) {
            return [0, 'check digit requires digits'];
        }
        $expected = (new Mod10())->calculate(substr($value, 0, -1));

        return $expected === $value[-1]
            ? null
            : [strlen($value) - 1, 'invalid check digit', 'expected ' . $expected];
    }

    /**
     * GS1 check character pair for GMN (AI 8013) and MUDI (AI 8014).
     *
     * @return array{0: int, 1: string, 2?: string}|null
     */
    private static function checkCharacterPair(string $value): ?array
    {
        $cset32 = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $length = strlen($value);

        if ($length < 2) {
            return [0, 'too short for a check character pair'];
        }
        if ($length > count(self::PRIMES) + 2) {
            return [0, 'too long for a check character pair'];
        }

        $sum = 0;
        for ($i = 0; $i < $length - 2; $i++) {
            $weight = strpos(CharacterSet::CSET82, $value[$i]);
            if ($weight === false) {
                return [$i, 'invalid character'];
            }
            $sum += $weight * self::PRIMES[$length - 3 - $i];
        }
        $sum %= 1021;
        $expected = $cset32[$sum >> 5] . $cset32[$sum & 31];

        return substr($value, -2) === $expected
            ? null
            : [$length - 2, 'invalid check character pair', 'expected ' . $expected];
    }

    /**
     * @return array{0: int, 1: string, 2?: string}|null
     */
    private static function companyPrefix(string $value, int $offset): ?array
    {
        $prefix = substr($value, $offset, 4);

        return strlen($prefix) === 4 && ctype_digit($prefix)
            ? null
            : [$offset, 'must start with a GS1 Company Prefix of at least 4 digits'];
    }

    /**
     * Validates YYYYMMDD. A two-digit year is placed in the century that puts
     * it closest to today (GS1 General Specifications 7.12).
     *
     * @param int $offset Characters of $date that were added and are not part of the value.
     *
     * @return array{0: int, 1: string, 2?: string}|null
     */
    private static function date(string $date, int $offset, bool $allowZeroDay, bool $twoDigitYear): ?array
    {
        if (strlen($date) !== 8 || !ctype_digit($date)) {
            return [0, 'date must be ' . ($twoDigitYear ? 'YYMMDD' : 'YYYYMMDD')];
        }

        $year = (int) substr($date, 0, 4);
        if ($twoDigitYear) {
            $current = (int) date('Y');
            $yy = $year % 100;
            $difference = $yy - $current % 100;
            $century = intdiv($current, 100) + ($difference >= 51 ? -1 : ($difference <= -50 ? 1 : 0));
            $year = $century * 100 + $yy;
        }
        $month = (int) substr($date, 4, 2);
        $day = (int) substr($date, 6, 2);

        if ($month < 1 || $month > 12) {
            return [4 - $offset, sprintf('invalid month "%s"', substr($date, 4, 2))];
        }
        if (($day === 0 && !$allowZeroDay) || $day > self::daysInMonth($year, $month)) {
            return [6 - $offset, sprintf('invalid day "%s"', substr($date, 6, 2))];
        }

        return null;
    }

    /**
     * @return array{0: int, 1: string, 2?: string}|null
     */
    private static function range(string $value, int $offset, int $length, int $max, string $what): ?array
    {
        $part = substr($value, $offset, $length);

        return strlen($part) === $length && ctype_digit($part) && (int) $part <= $max
            ? null
            : [$offset, sprintf('invalid %s "%s"', $what, $part)];
    }

    /**
     * @return array{0: int, 1: string, 2?: string}|null
     */
    private static function inList(string $list, string $value, string $problem): ?array
    {
        if (self::$lists === null) {
            self::$lists = [];
            $data = require __DIR__ . '/Resources/code-lists.php';
            foreach (is_array($data) ? $data : [] as $name => $codes) {
                foreach (is_array($codes) ? $codes : [] as $code) {
                    if (is_string($code)) {
                        self::$lists[(string) $name][$code] = true;
                    }
                }
            }
        }

        return isset(self::$lists[$list][$value]) ? null : [0, sprintf('"%s" %s', $value, $problem)];
    }

    /**
     * @return array{0: int, 1: string, 2?: string}|null
     */
    private static function mediaType(string $value): ?array
    {
        $type = (int) $value;

        return strlen($value) === 2 && (($type >= 1 && $type <= 10) || $type >= 80)
            ? null
            : [0, sprintf('"%s" is not an AIDC media type (01-10 or 80-99)', $value)];
    }

    /**
     * @return array{0: int, 1: string, 2?: string}|null
     */
    private static function pieceOfTotal(string $value): ?array
    {
        $half = intdiv(strlen($value), 2);
        $piece = substr($value, 0, $half);
        $total = substr($value, $half);

        return match (true) {
            strlen($value) % 2 !== 0 || $half === 0 => [0, 'piece and total must have equal length'],
            (int) $piece === 0 => [0, 'piece number must not be zero'],
            (int) $total === 0 => [$half, 'total number of pieces must not be zero'],
            (int) $piece > (int) $total => [0, 'piece number exceeds the total number of pieces'],
            default => null,
        };
    }

    /**
     * "position/total", e.g. "1/3".
     *
     * @return array{0: int, 1: string, 2?: string}|null
     */
    private static function positionInSequence(string $value): ?array
    {
        if (preg_match('#^([1-9]\d*)/([1-9]\d*)$#', $value, $match) !== 1) {
            return [0, 'must be "position/total" without leading zeros'];
        }

        return (int) $match[1] > (int) $match[2] ? [0, 'position exceeds the total'] : null;
    }

    /**
     * @return array{0: int, 1: string, 2?: string}|null
     */
    private static function percentEncoding(string $value): ?array
    {
        $offset = 0;
        while (($offset = strpos($value, '%', $offset)) !== false) {
            if (!ctype_xdigit(substr($value, $offset + 1, 2)) || strlen($value) < $offset + 3) {
                return [$offset, 'invalid percent-encoded sequence'];
            }
            $offset += 3;
        }

        return null;
    }

    /**
     * @return array{0: int, 1: string, 2?: string}|null
     */
    private static function iban(string $value): ?array
    {
        if (strlen($value) < 5 || strlen($value) > 34) {
            return [0, 'IBAN must be 5 to 34 characters'];
        }
        if (self::inList('iso3166alpha2', substr($value, 0, 2), '') !== null) {
            return [0, sprintf('"%s" is not an IBAN country code', substr($value, 0, 2))];
        }
        if (!ctype_digit(substr($value, 2, 2))) {
            return [2, 'IBAN check digits must be numeric'];
        }

        $rearranged = substr($value, 4) . substr($value, 0, 4);
        $remainder = 0;
        for ($i = 0, $length = strlen($rearranged); $i < $length; $i++) {
            $character = $rearranged[$i];
            if (ctype_digit($character)) {
                $remainder = ($remainder * 10 + (int) $character) % 97;
            } elseif (ctype_upper($character)) {
                $remainder = ($remainder * 100 + ord($character) - 55) % 97;
            } else {
                return [($i + 4) % $length, 'invalid IBAN character'];
            }
        }

        return $remainder === 1 ? null : [2, 'invalid IBAN check digits'];
    }

    private static function daysInMonth(int $year, int $month): int
    {
        return (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
    }
}
