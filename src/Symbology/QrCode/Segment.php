<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\QrCode;

use PhpBarcode\Encoding\BitBuffer;

/**
 * A run of data encoded in a single mode.
 *
 * The payload is already in the mode's own representation: digits for
 * numeric, the 45-character set for alphanumeric, raw bytes for byte mode and
 * two-byte Shift JIS pairs for Kanji.
 */
final readonly class Segment
{
    public int $count;

    public function __construct(
        public Mode $mode,
        public string $payload,
    ) {
        $this->count = $mode === Mode::Kanji ? intdiv(strlen($payload), 2) : strlen($payload);
    }

    public function dataBits(): int
    {
        return $this->mode->dataBits($this->count);
    }

    /**
     * Appends the data bits (without mode indicator or character count).
     */
    public function appendDataTo(BitBuffer $buffer): void
    {
        match ($this->mode) {
            Mode::Numeric => $this->appendNumeric($buffer),
            Mode::Alphanumeric => $this->appendAlphanumeric($buffer),
            Mode::Byte => $this->appendBytes($buffer),
            Mode::Kanji => $this->appendKanji($buffer),
        };
    }

    private function appendNumeric(BitBuffer $buffer): void
    {
        foreach (str_split($this->payload, 3) as $group) {
            $buffer->append((int) $group, [1 => 4, 2 => 7, 3 => 10][strlen($group)]);
        }
    }

    private function appendAlphanumeric(BitBuffer $buffer): void
    {
        foreach (str_split($this->payload, 2) as $pair) {
            $first = (int) strpos(Mode::ALPHANUMERIC_CHARSET, $pair[0]);
            if (strlen($pair) === 2) {
                $buffer->append($first * 45 + (int) strpos(Mode::ALPHANUMERIC_CHARSET, $pair[1]), 11);
            } else {
                $buffer->append($first, 6);
            }
        }
    }

    private function appendBytes(BitBuffer $buffer): void
    {
        foreach (str_split($this->payload) as $byte) {
            $buffer->append(ord($byte), 8);
        }
    }

    private function appendKanji(BitBuffer $buffer): void
    {
        foreach (str_split($this->payload, 2) as $pair) {
            $code = (ord($pair[0]) << 8) | ord($pair[1]);
            $code -= $code <= 0x9FFC ? 0x8140 : 0xC140;
            $buffer->append(($code >> 8) * 0xC0 + ($code & 0xFF), 13);
        }
    }
}
