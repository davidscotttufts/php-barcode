<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Encoding\BitBuffer;
use PhpBarcode\Encoding\GaloisField;
use PhpBarcode\Encoding\ReedSolomon;
use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\MatrixSymbol;
use PhpBarcode\Symbology\QrCode\ErrorCorrectionLevel;
use PhpBarcode\Symbology\QrCode\Masks;
use PhpBarcode\Symbology\QrCode\Mode;
use PhpBarcode\Symbology\QrCode\ModuleGrid;
use PhpBarcode\Symbology\QrCode\Segment;
use PhpBarcode\Symbology\QrCode\Segmenter;

/**
 * Micro QR Code (ISO/IEC 18004), versions M1-M4: 11×11 to 17×17 modules with
 * a single finder pattern, for very small labels.
 *
 * Capacity tops out at 35 digits, 21 alphanumeric characters or 15 bytes (M4-L).
 * M1 carries numeric data with error detection only and is used when the
 * requested level is L. Level H is not available; Q only in M4. Micro QR has
 * no ECI, GS1 or Structured Append support.
 *
 * Layout per ISO/IEC 18004:2015 §6-7, cross-checked against segno (BSD License).
 */
final class MicroQrCode extends AbstractSymbology
{
    /**
     * [data codewords, total codewords, data capacity in bits] per version and level.
     */
    private const CAPACITY = [
        1 => ['L' => [3, 5, 20]],
        2 => ['L' => [5, 10, 40], 'M' => [4, 10, 32]],
        3 => ['L' => [11, 17, 84], 'M' => [9, 17, 68]],
        4 => ['L' => [16, 24, 128], 'M' => [14, 24, 112], 'Q' => [10, 24, 80]],
    ];

    /** Symbol number in the format information, per version and level. */
    private const SYMBOL_NUMBER = [
        1 => ['L' => 0],
        2 => ['L' => 1, 'M' => 2],
        3 => ['L' => 3, 'M' => 4],
        4 => ['L' => 5, 'M' => 6, 'Q' => 7],
    ];

    /** Micro QR masks 0-3 are QR masks 1, 4, 6 and 7. */
    private const MASKS = [1, 4, 6, 7];

    /**
     * @param ErrorCorrectionLevel $errorCorrection L (default), M or Q (M4 only).
     * @param int|null             $version         Force M1-M4 (as 1-4); null picks the smallest that fits.
     * @param int|null             $mask            Force a mask pattern (0-3); null picks the best.
     * @param bool                 $kanji           Allow Kanji mode for Japanese characters.
     */
    public function __construct(
        private readonly ErrorCorrectionLevel $errorCorrection = ErrorCorrectionLevel::L,
        private readonly ?int $version = null,
        private readonly ?int $mask = null,
        private readonly bool $kanji = true,
    ) {
        if ($errorCorrection === ErrorCorrectionLevel::H) {
            throw new \InvalidArgumentException('Micro QR Code does not support error correction level H.');
        }
        if ($version !== null && ($version < 1 || $version > 4)) {
            throw new \InvalidArgumentException('Micro QR Code versions are M1-M4 (1-4).');
        }
        if ($version !== null && !isset(self::CAPACITY[$version][$errorCorrection->value])) {
            throw new \InvalidArgumentException(sprintf('Micro QR Code M%d does not support error correction level %s.', $version, $errorCorrection->value));
        }
        if ($mask !== null && ($mask < 0 || $mask > 3)) {
            throw new \InvalidArgumentException('Micro QR Code mask patterns are numbered 0-3.');
        }
    }

    public function name(): string
    {
        return 'Micro QR Code';
    }

    public function quietZone(): int
    {
        return 2;
    }

    protected function validate(string $data): void {}

    protected function doEncode(string $data): MatrixSymbol
    {
        $units = $this->units($data);

        $chosen = null;
        foreach ($this->version === null ? [1, 2, 3, 4] : [$this->version] as $version) {
            $capacity = $this->capacity($version);
            if ($capacity === null) {
                continue;
            }
            $segments = Segmenter::optimize(
                $units,
                [Mode::Numeric, Mode::Alphanumeric, Mode::Byte, Mode::Kanji],
                static fn(Mode $mode): ?int => ($bits = $mode->microCharacterCountBits($version)) === null ? null : $version - 1 + $bits,
            );
            if ($segments === null) {
                continue;
            }

            $bits = 0;
            foreach ($segments as $segment) {
                if ($segment->count >= 1 << (int) $segment->mode->microCharacterCountBits($version)) {
                    continue 2;
                }
                $bits += $version - 1 + (int) $segment->mode->microCharacterCountBits($version) + $segment->dataBits();
            }
            if ($bits <= $capacity[2]) {
                $chosen = [$version, $segments];
                break;
            }
        }

        if ($chosen === null) {
            throw new InvalidDataException(sprintf(
                'The data is too long for a Micro QR Code at error correction level %s%s.',
                $this->errorCorrection->value,
                $this->version === null ? '' : ' in version M' . $this->version,
            ));
        }

        [$version, $segments] = $chosen;
        $bitStream = $this->finalBits($this->dataBits($segments, $version), $version);
        $grid = $this->buildMatrix($bitStream, $version);

        return new MatrixSymbol(
            $grid->rows(),
            (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $data),
            $this->name() . ' M' . $version,
            $this->quietZone(),
            1,
            [['row' => 0, 'column' => 0, 'size' => 7]],
        );
    }

    /**
     * @return list<array{bytes: string, byte: bool, numeric: bool, alnum: ?string, kanji: ?string}>
     */
    private function units(string $data): array
    {
        if ($this->kanji && preg_match('/[\x80-\xFF]/', $data) === 1 && preg_match('//u', $data) === 1) {
            $units = Segmenter::units($data, true, false, nonAsciiBytes: false);
            foreach ($units as $unit) {
                if (!$unit['byte'] && $unit['kanji'] === null) {
                    return Segmenter::units($data, false, false);
                }
            }

            return $units;
        }

        return Segmenter::units($data, false, false);
    }

    /**
     * Mode headers, data, terminator and padding, as a bit string of exactly the data capacity.
     *
     * @param list<Segment> $segments
     */
    private function dataBits(array $segments, int $version): string
    {
        [, , $capacity] = $this->capacity($version) ?? throw new \LogicException('Unsupported version.');

        $buffer = new BitBuffer();
        foreach ($segments as $segment) {
            $buffer->append($segment->mode->microIndicator(), $version - 1);
            $buffer->append($segment->count, (int) $segment->mode->microCharacterCountBits($version));
            $segment->appendDataTo($buffer);
        }

        $buffer->append(0, min(2 * $version + 1, $capacity - count($buffer)));

        // M1 and M3 end in a 4-bit codeword, which is padded with zeros.
        $byteCapacity = $version % 2 === 1 ? $capacity - 4 : $capacity;
        if (count($buffer) < $byteCapacity) {
            $buffer->append(0, (8 - count($buffer) % 8) % 8);
            for ($pad = 0xEC; count($buffer) + 8 <= $byteCapacity; $pad ^= 0xEC ^ 0x11) {
                $buffer->append($pad, 8);
            }
        }

        return str_pad($buffer->toString(), $capacity, '0');
    }

    /**
     * Appends Reed–Solomon codewords (a single block in every Micro QR version).
     */
    private function finalBits(string $dataBits, int $version): string
    {
        [$dataCodewords, $totalCodewords] = $this->capacity($version) ?? throw new \LogicException('Unsupported version.');

        // The 4-bit final codeword of M1/M3 counts as the high nibble of a byte for error correction.
        $codewords = array_map(
            static fn(string $chunk): int => (int) bindec(str_pad($chunk, 8, '0')),
            str_split($dataBits, 8),
        );
        \assert(count($codewords) === $dataCodewords);

        $ecc = (new ReedSolomon(GaloisField::qrCode()))->encode($codewords, $totalCodewords - $dataCodewords);

        return $dataBits . implode('', array_map(static fn(int $c): string => str_pad(decbin($c), 8, '0', STR_PAD_LEFT), $ecc));
    }

    private function buildMatrix(string $bits, int $version): ModuleGrid
    {
        $size = 2 * $version + 9;
        $grid = new ModuleGrid($size, $size);

        $grid->drawFinder(3, 3);
        for ($i = 8; $i < $size; $i++) {
            $grid->setFunction($i, 0, $i % 2 === 0);
            $grid->setFunction(0, $i, $i % 2 === 0);
        }
        $this->drawFormatBits($grid, $version, 0); // reserve the area

        $grid->placeData($bits);

        $bestMask = $this->mask ?? 0;
        if ($this->mask === null) {
            $bestScore = -1;
            foreach (range(0, 3) as $mask) {
                $score = self::score($grid->masked(Masks::qr(self::MASKS[$mask])));
                if ($score > $bestScore) {
                    $bestMask = $mask;
                    $bestScore = $score;
                }
            }
        }

        $best = $grid->masked(Masks::qr(self::MASKS[$bestMask]));
        $this->drawFormatBits($best, $version, $bestMask);

        return $best;
    }

    /**
     * [data codewords, total codewords, data bits] for this level in $version, or null if unsupported.
     *
     * @return array{int, int, int}|null
     */
    private function capacity(int $version): ?array
    {
        return self::CAPACITY[$version][$this->errorCorrection->value] ?? null;
    }

    /**
     * Micro QR mask evaluation: favours dark modules along the right and bottom edges.
     */
    private static function score(ModuleGrid $grid): int
    {
        $sum1 = 0;
        $sum2 = 0;
        for ($i = 1; $i < $grid->width; $i++) {
            $sum1 += $grid->isDark($grid->width - 1, $i) ? 1 : 0;
            $sum2 += $grid->isDark($i, $grid->height - 1) ? 1 : 0;
        }

        return $sum1 <= $sum2 ? $sum1 * 16 + $sum2 : $sum2 * 16 + $sum1;
    }

    private function drawFormatBits(ModuleGrid $grid, int $version, int $mask): void
    {
        $data = (self::SYMBOL_NUMBER[$version][$this->errorCorrection->value] ?? throw new \LogicException('Unsupported version.')) << 2 | $mask;
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }
        $bits = ($data << 10 | $remainder) ^ 0x4445;

        for ($i = 0; $i < 8; $i++) {
            $grid->setFunction(8, $i + 1, (($bits >> $i) & 1) === 1);
            $grid->setFunction($i + 1, 8, (($bits >> (14 - $i)) & 1) === 1);
        }
    }
}
