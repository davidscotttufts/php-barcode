<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\LinearSymbol;

/**
 * Codabar (NW-7), used by libraries, blood banks and parcel services.
 *
 * Encodes 0-9 and - $ : / . +, framed by start/stop characters A, B, C or D.
 * If the data already begins and ends with A-D those are used as the guards;
 * otherwise $start and $stop are added.
 */
final class Codabar extends AbstractWideNarrowSymbology
{
    private const PATTERNS = [
        '0' => 'nnnnnww', '1' => 'nnnnwwn', '2' => 'nnnwnnw', '3' => 'wwnnnnn',
        '4' => 'nnwnnwn', '5' => 'wnnnnwn', '6' => 'nwnnnnw', '7' => 'nwnnwnn',
        '8' => 'nwwnnnn', '9' => 'wnnwnnn', '-' => 'nnnwwnn', '$' => 'nnwwnnn',
        ':' => 'wnnnwnw', '/' => 'wnwnnnw', '.' => 'wnwnwnn', '+' => 'nnwnwnw',
        'A' => 'nnwwnwn', 'B' => 'nwnwnnw', 'C' => 'nnnwnww', 'D' => 'nnnwwwn',
    ];

    private const DATA_ALPHABET = '0123456789-$:/.+';
    private const GUARDS = 'ABCD';

    public function __construct(
        private readonly string $start = 'A',
        private readonly string $stop = 'A',
        int $wideRatio = 3,
    ) {
        parent::__construct($wideRatio);

        foreach ([$start, $stop] as $guard) {
            if (strlen($guard) !== 1 || !str_contains(self::GUARDS, $guard)) {
                throw new \InvalidArgumentException('Codabar start/stop characters must be one of A, B, C or D.');
            }
        }
    }

    public function name(): string
    {
        return 'Codabar';
    }

    protected function normalize(string $data): string
    {
        return strtoupper($data);
    }

    protected function validate(string $data): void
    {
        if ($this->hasGuards($data)) {
            if (strlen($data) < 3) {
                throw InvalidDataException::invalidLength($this->name(), 'at least one data character between the guards', 0);
            }
            $inner = substr($data, 1, -1);
            $invalid = strspn($inner, self::DATA_ALPHABET);
            if ($invalid !== strlen($inner)) {
                throw InvalidDataException::invalidCharacter($this->name(), $inner[$invalid], $invalid + 1);
            }

            return;
        }

        $this->assertAlphabet($data, self::DATA_ALPHABET);
    }

    protected function doEncode(string $data): LinearSymbol
    {
        $framed = $this->hasGuards($data) ? $data : $this->start . $data . $this->stop;

        $characters = array_map(
            fn(string $character): string => $this->expand(self::PATTERNS[$character]),
            str_split($framed),
        );

        return $this->fromWidths(implode('1', $characters), substr($framed, 1, -1));
    }

    private function hasGuards(string $data): bool
    {
        return strlen($data) >= 2
            && str_contains(self::GUARDS, $data[0])
            && str_contains(self::GUARDS, $data[-1]);
    }
}
