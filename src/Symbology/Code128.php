<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\LinearSymbol;

/**
 * Code 128, a high-density symbology for the full ASCII range (0x00-0x7F).
 *
 * By default the encoder picks code sets automatically, switching to code set
 * C for runs of digits (which halves their width) and to code set A for
 * control characters. Pass a Code128Subset to force a single code set.
 *
 * With $fnc1 enabled the symbol starts with the FNC1 function character and
 * every ASCII GS (0x1D) in the data is encoded as FNC1, which is how GS1-128
 * separates variable-length fields (see Gs1128).
 */
final class Code128 extends AbstractSymbology
{
    /** Bar/space widths for symbol values 0-106, indexed by value. */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312',
        '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
        '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131',
        '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
        '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
        '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321',
        '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
        '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114',
        '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112',
        '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
        '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412',
        '211214', '211232', '2331112',
    ];

    private const STOP = 106;

    /** Symbol value of the FNC1 function character (the same in code sets A, B and C). */
    private const FNC1 = 102;

    /** ASCII Group Separator, encoded as FNC1 when FNC1 mode is on. */
    public const GS = "\x1D";

    /** A digit run must be at least this long to be worth switching to code set C mid-symbol. */
    private const MIN_DIGITS_MID = 6;

    /** …or this long when it starts or ends the data. */
    private const MIN_DIGITS_EDGE = 4;

    public function __construct(
        private readonly ?Code128Subset $subset = null,
        private readonly bool $fnc1 = false,
    ) {}

    public function name(): string
    {
        return $this->subset === null ? 'Code 128' : 'Code 128' . $this->subset->value;
    }

    protected function validate(string $data): void
    {
        $subset = $this->subset;
        match ($subset) {
            null => $this->assertBytes($data, static fn(int $byte): bool => $byte <= 127),
            Code128Subset::C => $this->validateSubsetC($data),
            default => $this->assertBytes($data, $subset->canEncode(...)),
        };
    }

    protected function doEncode(string $data): LinearSymbol
    {
        $values = $this->subset === null ? $this->encodeAuto($data) : $this->encodeForced($data, $this->subset);
        $values[] = $this->checksum($values);
        $values[] = self::STOP;

        $pattern = implode('', array_map(static fn(int $value): string => self::PATTERNS[$value], $values));

        return $this->fromWidths($pattern, self::printable($data));
    }

    /**
     * @return list<int>
     */
    private function encodeForced(string $data, Code128Subset $subset): array
    {
        $values = $this->start($subset);

        if ($subset === Code128Subset::C) {
            foreach ($this->fnc1 ? explode(self::GS, $data) : [$data] as $index => $run) {
                if ($index > 0) {
                    $values[] = self::FNC1;
                }
                foreach ($run === '' ? [] : str_split($run, 2) as $pair) {
                    $values[] = (int) $pair;
                }
            }

            return $values;
        }

        foreach (str_split($data) as $character) {
            $values[] = $this->isFnc1($character) ? self::FNC1 : $subset->valueOf(ord($character));
        }

        return $values;
    }

    /**
     * Chooses code sets greedily, producing a compact symbol.
     *
     * @return list<int>
     */
    private function encodeAuto(string $data): array
    {
        $length = strlen($data);
        $leadingDigits = strspn($data, '0123456789');
        $leadingRunIsWhole = $this->endsField($data, $leadingDigits);

        $set = ($leadingDigits >= self::MIN_DIGITS_EDGE || ($leadingRunIsWhole && $leadingDigits >= 2 && $leadingDigits % 2 === 0))
            ? Code128Subset::C
            : $this->preferredTextSet($data, 0);

        $values = $this->start($set);
        $position = 0;

        while ($position < $length) {
            // FNC1 is available in every code set, so it never forces a switch.
            if ($this->isFnc1($data[$position])) {
                $values[] = self::FNC1;
                $position++;
                continue;
            }

            $digits = strspn($data, '0123456789', $position);

            if ($set === Code128Subset::C) {
                if ($digits >= 2) {
                    $values[] = (int) substr($data, $position, 2);
                    $position += 2;
                    continue;
                }
                $set = $this->preferredTextSet($data, $position);
                $values[] = $set->switchValue();
                continue;
            }

            $reachesEnd = $this->endsField($data, $position + $digits);
            if ($digits >= self::MIN_DIGITS_MID || ($reachesEnd && $digits >= self::MIN_DIGITS_EDGE)) {
                // Keep pairs aligned by emitting an odd leading digit in the current set.
                if ($digits % 2 === 1) {
                    $values[] = $set->valueOf(ord($data[$position]));
                    $position++;
                }
                $set = Code128Subset::C;
                $values[] = $set->switchValue();
                continue;
            }

            $byte = ord($data[$position]);
            if (!$set->canEncode($byte)) {
                $set = $set === Code128Subset::A ? Code128Subset::B : Code128Subset::A;
                $values[] = $set->switchValue();
            }
            $values[] = $set->valueOf($byte);
            $position++;
        }

        return $values;
    }

    /**
     * Code set A if a control character appears before any lower-case one, else B.
     */
    private function preferredTextSet(string $data, int $from): Code128Subset
    {
        for ($i = $from, $length = strlen($data); $i < $length; $i++) {
            $byte = ord($data[$i]);
            if ($this->isFnc1($data[$i])) {
                continue;
            }
            if ($byte < 32) {
                return Code128Subset::A;
            }
            if ($byte >= 96) {
                return Code128Subset::B;
            }
        }

        return Code128Subset::B;
    }

    /**
     * @param list<int> $values Start value followed by data values.
     */
    private function checksum(array $values): int
    {
        $sum = $values[0];
        foreach (array_slice($values, 1) as $index => $value) {
            $sum += $value * ($index + 1);
        }

        return $sum % 103;
    }

    /**
     * @param callable(int): bool $accepts
     */
    private function assertBytes(string $data, callable $accepts): void
    {
        foreach (str_split($data) as $position => $character) {
            if (!$this->isFnc1($character) && !$accepts(ord($character))) {
                throw InvalidDataException::invalidCharacter($this->name(), $character, $position);
            }
        }
    }

    private function validateSubsetC(string $data): void
    {
        $this->assertAlphabet($data, $this->fnc1 ? '0123456789' . self::GS : '0123456789');

        foreach ($this->fnc1 ? explode(self::GS, $data) : [$data] as $run) {
            if (strlen($run) % 2 !== 0) {
                throw InvalidDataException::invalidLength($this->name(), 'an even number of digits', strlen($run));
            }
        }
    }

    /**
     * Start character, followed by FNC1 in FNC1 mode.
     *
     * @return list<int>
     */
    private function start(Code128Subset $set): array
    {
        return $this->fnc1 ? [$set->startValue(), self::FNC1] : [$set->startValue()];
    }

    private function isFnc1(string $character): bool
    {
        return $this->fnc1 && $character === self::GS;
    }

    /**
     * Whether $offset is the end of the data or (in FNC1 mode) of a GS-delimited field,
     * after which code set C can continue directly with FNC1.
     */
    private function endsField(string $data, int $offset): bool
    {
        return $offset === strlen($data) || $this->isFnc1($data[$offset]);
    }

    private static function printable(string $data): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $data);
    }
}
