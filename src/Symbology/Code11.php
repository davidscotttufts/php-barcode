<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Symbol\LinearSymbol;

/**
 * Code 11 (USD-8), used for labelling telecommunications equipment.
 *
 * Encodes 0-9 and "-". One check character (C) is appended for data of up
 * to 10 characters and two (C and K) for longer data, unless $checkDigits
 * forces 0, 1 or 2.
 */
final class Code11 extends AbstractWideNarrowSymbology
{
    private const ALPHABET = '0123456789-';

    private const PATTERNS = [
        '0' => 'nnnnw', '1' => 'wnnnw', '2' => 'nwnnw', '3' => 'wwnnn', '4' => 'nnwnw',
        '5' => 'wnwnn', '6' => 'nwwnn', '7' => 'nnnww', '8' => 'wnnwn', '9' => 'wnnnn',
        '-' => 'nnwnn',
    ];

    private const GUARD = 'nnwwn';

    /**
     * @param int|null $checkDigits 0, 1 or 2 check characters; null chooses automatically.
     */
    public function __construct(
        private readonly ?int $checkDigits = null,
        int $wideRatio = 2,
    ) {
        if ($checkDigits !== null && ($checkDigits < 0 || $checkDigits > 2)) {
            throw new \InvalidArgumentException('Code 11 uses 0, 1 or 2 check characters.');
        }
        parent::__construct($wideRatio);
    }

    public function name(): string
    {
        return 'Code 11';
    }

    protected function validate(string $data): void
    {
        $this->assertAlphabet($data, self::ALPHABET);
    }

    protected function doEncode(string $data): LinearSymbol
    {
        $count = $this->checkDigits ?? (strlen($data) <= 10 ? 1 : 2);

        $payload = $data;
        if ($count >= 1) {
            $payload .= self::check($payload, 10);
        }
        if ($count === 2) {
            $payload .= self::check($payload, 9);
        }

        $characters = array_map(
            fn(string $c): string => $this->expand(self::PATTERNS[$c]),
            str_split($payload),
        );
        array_unshift($characters, $this->expand(self::GUARD));
        $characters[] = $this->expand(self::GUARD);

        return $this->fromWidths(implode('1', $characters), $payload);
    }

    /**
     * Check character: weights 1..$maxWeight cycling from the right, modulo 11.
     */
    public static function check(string $data, int $maxWeight): string
    {
        $sum = 0;
        foreach (str_split(strrev($data)) as $i => $c) {
            $sum += (int) strpos(self::ALPHABET, $c) * ($i % $maxWeight + 1);
        }

        return self::ALPHABET[$sum % 11];
    }
}
