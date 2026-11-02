<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology\Aztec;

use PhpBarcode\Encoding\BitBuffer;
use PhpBarcode\Encoding\GaloisField;
use PhpBarcode\Encoding\ReedSolomon;

/**
 * Lays out an Aztec symbol: chooses the size, stuffs bits, adds Reed–Solomon
 * check words, and draws the data layers, mode message, bullseye and
 * reference grid (ISO/IEC 24778).
 *
 * Layout after the ZXing Aztec Encoder (Apache License 2.0); Aztec Runes
 * after Zint (BSD 3-Clause).
 *
 * @internal
 */
final class AztecEncoder
{
    /** Codeword size in bits, indexed by layer count. */
    private const WORD_SIZE = [
        4, 6, 6, 8, 8, 8, 8, 8, 8, 10, 10, 10, 10, 10, 10, 10, 10,
        10, 10, 10, 10, 10, 10, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12,
    ];

    public const MAX_COMPACT_LAYERS = 4;
    public const MAX_LAYERS = 32;

    /**
     * @param BitBuffer $data              Output of HighLevelEncoder.
     * @param int       $minEccPercent     Minimum share of the symbol used for error correction.
     * @param int|null  $layers            Exact layer count, or null to choose the smallest that fits.
     * @param bool|null $compact           Force compact (true) or full-range (false) symbols; null allows both.
     *
     * @throws \OverflowException when the data does not fit
     */
    public function encode(BitBuffer $data, int $minEccPercent = 23, ?int $layers = null, ?bool $compact = null): AztecMatrix
    {
        $bits = $data->toString();
        $eccBits = intdiv(strlen($bits) * $minEccPercent, 100) + 11;
        $totalSizeBits = strlen($bits) + $eccBits;

        $chosen = null;
        foreach ($this->candidates($layers, $compact) as [$isCompact, $layerCount]) {
            $capacity = self::totalBitsInLayer($layerCount, $isCompact);
            if ($layers === null && $totalSizeBits > $capacity) {
                continue;
            }
            $wordSize = self::WORD_SIZE[$layerCount];
            $stuffed = self::stuffBits($bits, $wordSize);
            $usable = $capacity - ($capacity % $wordSize);
            $maxWords = $isCompact ? 64 : 2048; // limited by the mode message's length field

            if (strlen($stuffed) + $eccBits <= $usable && strlen($stuffed) <= $wordSize * $maxWords) {
                $chosen = [$isCompact, $layerCount, $wordSize, $stuffed, $capacity];
                break;
            }
        }

        if ($chosen === null) {
            throw new \OverflowException($layers === null
                ? 'The data is too large for an Aztec Code.'
                : sprintf('The data does not fit in %d layers at %d%% error correction.', $layers, $minEccPercent));
        }

        [$isCompact, $layerCount, $wordSize, $stuffed, $capacity] = $chosen;

        $messageBits = self::addCheckWords($stuffed, $capacity, $wordSize);
        $modeMessage = self::modeMessage($isCompact, $layerCount, intdiv(strlen($stuffed), $wordSize));

        return $this->draw($isCompact, $layerCount, $messageBits, $modeMessage);
    }

    /**
     * An Aztec Rune: an 11×11 compact core carrying a single value 0-255.
     */
    public function rune(int $value): AztecMatrix
    {
        $words = self::addCheckWords(str_pad(decbin($value), 8, '0', STR_PAD_LEFT), 28, 4);

        // Runes are distinguished from compact symbols by inverting alternate mode-message bits.
        $modeMessage = '';
        for ($i = 0; $i < 28; $i++) {
            $modeMessage .= $i % 2 === 0 ? ($words[$i] === '1' ? '0' : '1') : $words[$i];
        }

        return $this->draw(true, 0, '', $modeMessage);
    }

    /**
     * Sizes to try, smallest first.
     *
     * @return \Generator<int, array{bool, int}>
     */
    private function candidates(?int $layers, ?bool $compact): \Generator
    {
        if ($layers !== null) {
            yield [$compact ?? $layers <= self::MAX_COMPACT_LAYERS, $layers];

            return;
        }

        if ($compact !== false) {
            for ($i = 1; $i <= self::MAX_COMPACT_LAYERS; $i++) {
                yield [true, $i];
            }
        }
        if ($compact !== true) {
            // A full-range symbol with 1-3 layers is never smaller than the equivalent compact one.
            for ($i = $compact === false ? 1 : 4; $i <= self::MAX_LAYERS; $i++) {
                yield [false, $i];
            }
        }
    }

    private static function totalBitsInLayer(int $layers, bool $compact): int
    {
        return (($compact ? 88 : 112) + 16 * $layers) * $layers;
    }

    /**
     * Splits into words, inserting a complementary bit wherever a word would
     * otherwise be all zeros or all ones (which are reserved).
     */
    private static function stuffBits(string $bits, int $wordSize): string
    {
        $out = '';
        $length = strlen($bits);
        $mask = (1 << $wordSize) - 2;

        for ($i = 0; $i < $length; $i += $wordSize) {
            $word = 0;
            for ($j = 0; $j < $wordSize; $j++) {
                // Pad the final word with ones.
                if ($i + $j >= $length || $bits[$i + $j] === '1') {
                    $word |= 1 << ($wordSize - 1 - $j);
                }
            }

            if (($word & $mask) === $mask) {
                $out .= self::bin($word & $mask, $wordSize);
                $i--;
            } elseif (($word & $mask) === 0) {
                $out .= self::bin($word | 1, $wordSize);
                $i--;
            } else {
                $out .= self::bin($word, $wordSize);
            }
        }

        return $out;
    }

    /**
     * Appends Reed–Solomon words to fill $totalBits, left-padding with zeros to a whole word count.
     *
     * @param positive-int $wordSize
     */
    private static function addCheckWords(string $bits, int $totalBits, int $wordSize): string
    {
        $data = array_map(static fn(string $word): int => (int) bindec($word), str_split($bits, $wordSize));
        $totalWords = intdiv($totalBits, $wordSize);
        $ecc = (new ReedSolomon(GaloisField::aztec($wordSize)))->encode($data, $totalWords - count($data));

        $out = str_repeat('0', $totalBits % $wordSize);
        foreach ([...$data, ...$ecc] as $word) {
            $out .= self::bin($word, $wordSize);
        }

        return $out;
    }

    private static function modeMessage(bool $compact, int $layers, int $words): string
    {
        $bits = $compact
            ? self::bin($layers - 1, 2) . self::bin($words - 1, 6)
            : self::bin($layers - 1, 5) . self::bin($words - 1, 11);

        return self::addCheckWords($bits, $compact ? 28 : 40, 4);
    }

    private function draw(bool $compact, int $layers, string $messageBits, string $modeMessage): AztecMatrix
    {
        $baseSize = ($compact ? 11 : 14) + $layers * 4;
        $alignment = [];

        if ($compact) {
            $size = $baseSize;
            $alignment = range(0, $baseSize - 1);
        } else {
            // Full-range symbols gain a reference-grid line every 16 modules from the center.
            $size = $baseSize + 1 + 2 * intdiv(intdiv($baseSize, 2) - 1, 15);
            $origCenter = intdiv($baseSize, 2);
            $center = intdiv($size, 2);
            for ($i = 0; $i < $origCenter; $i++) {
                $offset = $i + intdiv($i, 15);
                $alignment[$origCenter - $i - 1] = $center - $offset - 1;
                $alignment[$origCenter + $i] = $center + $offset + 1;
            }
        }

        $matrix = new AztecMatrix($size);
        $bit = static fn(int $index): bool => ($messageBits[$index] ?? '0') === '1';

        // Data layers spiral inwards, two modules thick, clockwise from the top-left.
        for ($i = 0, $rowOffset = 0; $i < $layers; $i++) {
            $rowSize = ($layers - $i) * 4 + ($compact ? 9 : 12);
            for ($j = 0; $j < $rowSize; $j++) {
                $columnOffset = $j * 2;
                for ($k = 0; $k < 2; $k++) {
                    if ($bit($rowOffset + $columnOffset + $k)) {
                        $matrix->set($alignment[$i * 2 + $k], $alignment[$i * 2 + $j]);
                    }
                    if ($bit($rowOffset + $rowSize * 2 + $columnOffset + $k)) {
                        $matrix->set($alignment[$i * 2 + $j], $alignment[$baseSize - 1 - $i * 2 - $k]);
                    }
                    if ($bit($rowOffset + $rowSize * 4 + $columnOffset + $k)) {
                        $matrix->set($alignment[$baseSize - 1 - $i * 2 - $k], $alignment[$baseSize - 1 - $i * 2 - $j]);
                    }
                    if ($bit($rowOffset + $rowSize * 6 + $columnOffset + $k)) {
                        $matrix->set($alignment[$baseSize - 1 - $i * 2 - $j], $alignment[$i * 2 + $k]);
                    }
                }
            }
            $rowOffset += $rowSize * 8;
        }

        $this->drawModeMessage($matrix, $compact, $size, $modeMessage);

        $center = intdiv($size, 2);
        if ($compact) {
            $this->drawBullseye($matrix, $center, 5);
        } else {
            $this->drawBullseye($matrix, $center, 7);
            for ($i = 0, $j = 0; $i < intdiv($baseSize, 2) - 1; $i += 15, $j += 16) {
                for ($k = $center & 1; $k < $size; $k += 2) {
                    $matrix->set($center - $j, $k);
                    $matrix->set($center + $j, $k);
                    $matrix->set($k, $center - $j);
                    $matrix->set($k, $center + $j);
                }
            }
        }

        $matrix->compact = $compact;
        $matrix->layers = $layers;

        return $matrix;
    }

    private function drawModeMessage(AztecMatrix $matrix, bool $compact, int $size, string $modeMessage): void
    {
        $center = intdiv($size, 2);
        $bit = static fn(int $index): bool => $modeMessage[$index] === '1';

        if ($compact) {
            for ($i = 0; $i < 7; $i++) {
                $offset = $center - 3 + $i;
                if ($bit($i)) {
                    $matrix->set($offset, $center - 5);
                }
                if ($bit($i + 7)) {
                    $matrix->set($center + 5, $offset);
                }
                if ($bit(20 - $i)) {
                    $matrix->set($offset, $center + 5);
                }
                if ($bit(27 - $i)) {
                    $matrix->set($center - 5, $offset);
                }
            }

            return;
        }

        for ($i = 0; $i < 10; $i++) {
            $offset = $center - 5 + $i + intdiv($i, 5);
            if ($bit($i)) {
                $matrix->set($offset, $center - 7);
            }
            if ($bit($i + 10)) {
                $matrix->set($center + 7, $offset);
            }
            if ($bit(29 - $i)) {
                $matrix->set($offset, $center + 7);
            }
            if ($bit(39 - $i)) {
                $matrix->set($center - 7, $offset);
            }
        }
    }

    /**
     * Concentric square rings plus the orientation marks at the corners.
     */
    private function drawBullseye(AztecMatrix $matrix, int $center, int $size): void
    {
        for ($i = 0; $i < $size; $i += 2) {
            for ($j = $center - $i; $j <= $center + $i; $j++) {
                $matrix->set($j, $center - $i);
                $matrix->set($j, $center + $i);
                $matrix->set($center - $i, $j);
                $matrix->set($center + $i, $j);
            }
        }

        $matrix->set($center - $size, $center - $size);
        $matrix->set($center - $size + 1, $center - $size);
        $matrix->set($center - $size, $center - $size + 1);
        $matrix->set($center + $size, $center - $size);
        $matrix->set($center + $size, $center - $size + 1);
        $matrix->set($center + $size, $center + $size - 1);
    }

    private static function bin(int $value, int $length): string
    {
        return str_pad(decbin($value), $length, '0', STR_PAD_LEFT);
    }
}
