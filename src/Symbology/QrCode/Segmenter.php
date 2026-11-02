<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\QrCode;

/**
 * Splits input into the bit-cheapest sequence of mode segments.
 *
 * Uses dynamic programming over characters, tracking the cost (in sixths of a
 * bit, so numeric's 10-bits-per-3-digits stays integral) of being in each mode
 * after each character, including the header cost of switching modes.
 * The approach follows Project Nayuki's QR Code generator (MIT License).
 *
 * @phpstan-type Unit array{bytes: string, byte: bool, numeric: bool, alnum: ?string, kanji: ?string}
 */
final class Segmenter
{
    /**
     * Breaks $data into characters annotated with how each mode would encode them.
     *
     * @param bool $kanji Allow Kanji mode for characters representable in Shift JIS.
     * @param bool $gs1   GS1 mode: ASCII GS (0x1D) is FNC1, written "%" in alphanumeric mode, and "%" is escaped as "%%".
     * @param bool $nonAsciiBytes Allow byte mode for non-ASCII characters (false forces them into Kanji mode).
     *
     * @return list<Unit>
     */
    public static function units(string $data, bool $kanji, bool $gs1, bool $nonAsciiBytes = true): array
    {
        $isUtf8 = preg_match('//u', $data) === 1;
        $characters = $isUtf8 ? (preg_split('//u', $data, -1, PREG_SPLIT_NO_EMPTY) ?: []) : str_split($data);
        $kanji = $kanji && $isUtf8 && function_exists('mb_convert_encoding');

        $units = [];
        foreach ($characters as $character) {
            $alnum = null;
            if ($gs1 && $character === "\x1D") {
                $alnum = '%';
            } elseif ($gs1 && $character === '%') {
                $alnum = '%%';
            } elseif (strlen($character) === 1 && str_contains(Mode::ALPHANUMERIC_CHARSET, $character)) {
                $alnum = $character;
            }

            $units[] = [
                'bytes' => $character,
                'byte' => $nonAsciiBytes || preg_match('/[\x80-\xFF]/', $character) !== 1,
                'numeric' => strlen($character) === 1 && ctype_digit($character),
                'alnum' => $alnum,
                'kanji' => $kanji && strlen($character) > 1 ? self::shiftJisKanji($character) : null,
            ];
        }

        return $units;
    }

    /**
     * The optimal segmentation for the given character-count widths.
     *
     * @param list<Unit>                   $units
     * @param list<Mode>                   $modes      Modes allowed in this symbol.
     * @param \Closure(Mode): (int|null)   $headerBits Mode indicator + character count bits, or null if the mode is unavailable.
     *
     * @return list<Segment>|null Null when some character cannot be encoded by any allowed mode.
     */
    public static function optimize(array $units, array $modes, \Closure $headerBits): ?array
    {
        $count = count($units);
        if ($count === 0) {
            return [];
        }

        $modes = array_values(array_filter($modes, static fn(Mode $mode): bool => $headerBits($mode) !== null));
        $numModes = count($modes);
        if ($numModes === 0) {
            return null;
        }
        $headCosts = array_map(static fn(Mode $mode): int => (int) $headerBits($mode) * 6, $modes);

        $inf = PHP_INT_MAX >> 2;
        $previous = $headCosts;
        /** @var list<list<int|null>> $charModes index into $modes used for char i when in state j afterwards */
        $charModes = [];

        foreach ($units as $i => $unit) {
            $current = array_fill(0, $numModes, $inf);
            $chosen = array_fill(0, $numModes, null);

            foreach ($modes as $j => $mode) {
                $cost = self::unitCost($unit, $mode);
                if ($cost !== null) {
                    $current[$j] = $previous[$j] + $cost;
                    $chosen[$j] = $j;
                }
            }

            // Optionally start a new segment (of mode j) after encoding this character in mode k.
            $settled = $current;
            $direct = $chosen;
            for ($j = 0; $j < $numModes; $j++) {
                for ($k = 0; $k < $numModes; $k++) {
                    if ($direct[$k] === null || $j === $k) {
                        continue;
                    }
                    $switched = intdiv($settled[$k] + 5, 6) * 6 + $headCosts[$j];
                    if ($chosen[$j] === null || $switched < $current[$j]) {
                        $current[$j] = $switched;
                        $chosen[$j] = $k;
                    }
                }
            }

            if (array_filter($chosen, static fn(?int $c): bool => $c !== null) === []) {
                return null;
            }

            $charModes[$i] = $chosen;
            $previous = $current;
        }

        // Trace back from the cheapest final state.
        $state = array_keys($previous, min($previous), true)[0];
        $assigned = array_fill(0, $count, 0);
        for ($i = $count - 1; $i >= 0; $i--) {
            $state = $charModes[$i][$state];
            if ($state === null) {
                return null;
            }
            $assigned[$i] = $state;
        }

        $segments = [];
        $runMode = $assigned[0];
        $payload = '';
        foreach ($units as $i => $unit) {
            if ($assigned[$i] !== $runMode) {
                $segments[] = new Segment($modes[$runMode], $payload);
                $runMode = $assigned[$i];
                $payload = '';
            }
            $payload .= self::representation($unit, $modes[$runMode]);
        }
        $segments[] = new Segment($modes[$runMode], $payload);

        return $segments;
    }

    /**
     * @param Unit $unit
     */
    private static function unitCost(array $unit, Mode $mode): ?int
    {
        return match ($mode) {
            Mode::Numeric => $unit['numeric'] ? 20 : null,
            Mode::Alphanumeric => $unit['alnum'] === null ? null : 33 * strlen($unit['alnum']),
            Mode::Byte => $unit['byte'] ? 48 * strlen($unit['bytes']) : null,
            Mode::Kanji => $unit['kanji'] === null ? null : 78,
        };
    }

    /**
     * @param Unit $unit
     */
    private static function representation(array $unit, Mode $mode): string
    {
        return match ($mode) {
            Mode::Numeric, Mode::Byte => $unit['bytes'],
            Mode::Alphanumeric => (string) $unit['alnum'],
            Mode::Kanji => (string) $unit['kanji'],
        };
    }

    /**
     * The two Shift JIS bytes for a character that QR Kanji mode can encode, or null.
     */
    private static function shiftJisKanji(string $character): ?string
    {
        $sjis = mb_convert_encoding($character, 'SJIS', 'UTF-8');
        if (!is_string($sjis) || strlen($sjis) !== 2 || mb_convert_encoding($sjis, 'UTF-8', 'SJIS') !== $character) {
            return null;
        }

        $code = (ord($sjis[0]) << 8) | ord($sjis[1]);
        $low = $code & 0xFF;
        $inRange = ($code >= 0x8140 && $code <= 0x9FFC) || ($code >= 0xE040 && $code <= 0xEBBF);

        return $inRange && $low >= 0x40 && $low !== 0x7F && $low <= 0xFC ? $sjis : null;
    }
}
