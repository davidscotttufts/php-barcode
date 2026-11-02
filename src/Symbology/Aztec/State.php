<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\Aztec;

use PhpBarcode\Encoding\BitBuffer;

/**
 * A candidate partial encoding: the current mode, the tokens emitted so far,
 * any open binary-shift run, and the total bit cost.
 *
 * @internal
 */
final readonly class State
{
    public int $binaryShiftCost;

    public function __construct(
        public ?Token $token,
        public int $mode,
        public int $binaryShiftByteCount,
        public int $bitCount,
    ) {
        $this->binaryShiftCost = match (true) {
            $binaryShiftByteCount > 62 => 21,
            $binaryShiftByteCount > 31 => 20,
            $binaryShiftByteCount > 0 => 10,
            default => 0,
        };
    }

    public static function initial(): self
    {
        return new self(null, HighLevelEncoder::MODE_UPPER, 0, 0);
    }

    /**
     * Latches to $mode (if needed) and appends $value in it.
     */
    public function latchAndAppend(int $mode, int $value): self
    {
        $token = $this->token;
        $bitCount = $this->bitCount;

        if ($mode !== $this->mode) {
            $latch = HighLevelEncoder::LATCH[$this->mode][$mode];
            $token = Token::add($token, $latch & 0xFFFF, $latch >> 16);
            $bitCount += $latch >> 16;
        }

        $width = $mode === HighLevelEncoder::MODE_DIGIT ? 4 : 5;

        return new self(Token::add($token, $value, $width), $mode, 0, $bitCount + $width);
    }

    /**
     * Shifts temporarily to $mode for one value, staying in the current mode.
     */
    public function shiftAndAppend(int $mode, int $value): self
    {
        $width = $this->mode === HighLevelEncoder::MODE_DIGIT ? 4 : 5;
        $token = Token::add($this->token, HighLevelEncoder::SHIFT[$this->mode][$mode], $width);
        $token = Token::add($token, $value, 5);

        return new self($token, $this->mode, 0, $this->bitCount + $width + 5);
    }

    /**
     * Adds input byte $index to a binary-shift run, opening one if necessary.
     */
    public function addBinaryShiftChar(int $index): self
    {
        $token = $this->token;
        $mode = $this->mode;
        $bitCount = $this->bitCount;

        // Binary shift is only available from Upper, Lower and Mixed.
        if ($mode === HighLevelEncoder::MODE_PUNCT || $mode === HighLevelEncoder::MODE_DIGIT) {
            $latch = HighLevelEncoder::LATCH[$mode][HighLevelEncoder::MODE_UPPER];
            $token = Token::add($token, $latch & 0xFFFF, $latch >> 16);
            $bitCount += $latch >> 16;
            $mode = HighLevelEncoder::MODE_UPPER;
        }

        $delta = match ($this->binaryShiftByteCount) {
            0, 31 => 18,
            62 => 9,
            default => 8,
        };

        $result = new self($token, $mode, $this->binaryShiftByteCount + 1, $bitCount + $delta);

        // The longest possible run is 2047 + 31 bytes.
        return $result->binaryShiftByteCount === 2047 + 31 ? $result->endBinaryShift($index + 1) : $result;
    }

    /**
     * Closes any open binary-shift run that ends just before input position $index.
     */
    public function endBinaryShift(int $index): self
    {
        if ($this->binaryShiftByteCount === 0) {
            return $this;
        }

        $token = Token::addBinaryShift($this->token, $index - $this->binaryShiftByteCount, $this->binaryShiftByteCount);

        return new self($token, $this->mode, 0, $this->bitCount);
    }

    /**
     * Appends FLG(n): FNC1 when $eci is null, otherwise an ECI designator.
     */
    public function appendFlg(?int $eci): self
    {
        $result = $this->mode === HighLevelEncoder::MODE_PUNCT
            ? new self(Token::add($this->token, 0, 5), $this->mode, 0, $this->bitCount + 5)
            : $this->shiftAndAppend(HighLevelEncoder::MODE_PUNCT, 0);

        $token = $result->token;
        $added = 3;

        if ($eci === null) {
            $token = Token::add($token, 0, 3); // FLG(0) = FNC1
        } else {
            $digits = (string) $eci;
            $token = Token::add($token, strlen($digits), 3);
            foreach (str_split($digits) as $digit) {
                $token = Token::add($token, (int) $digit + 2, 4);
            }
            $added += 4 * strlen($digits);
        }

        return new self($token, $result->mode, 0, $result->bitCount + $added);
    }

    /**
     * Whether this state is at least as good as $other in every future.
     */
    public function isBetterThanOrEqualTo(self $other): bool
    {
        $bits = $this->bitCount + (HighLevelEncoder::LATCH[$this->mode][$other->mode] >> 16);

        if ($this->binaryShiftByteCount < $other->binaryShiftByteCount) {
            $bits += $other->binaryShiftCost - $this->binaryShiftCost;
        } elseif ($this->binaryShiftByteCount > $other->binaryShiftByteCount && $other->binaryShiftByteCount > 0) {
            $bits += 10;
        }

        return $bits <= $other->bitCount;
    }

    /**
     * @param list<int> $input
     */
    public function toBits(array $input): BitBuffer
    {
        $tokens = [];
        for ($token = $this->endBinaryShift(count($input))->token; $token !== null; $token = $token->previous) {
            $tokens[] = $token;
        }

        $bits = new BitBuffer();
        foreach (array_reverse($tokens) as $token) {
            $token->appendTo($bits, $input);
        }

        return $bits;
    }
}
