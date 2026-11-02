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
 * Rectangular Micro QR Code (rMQR, ISO/IEC 23941): 32 sizes from R7×43 to
 * R17×139, for long narrow spaces such as cables, tubes and PCB edges.
 *
 * Supports error correction levels M and H, numeric, alphanumeric, byte and
 * Kanji modes, ECI 26 for UTF-8 text and GS1. The smallest-area size that
 * fits is chosen automatically, optionally restricted to a fixed height.
 *
 * Tables are from ISO/IEC 23941:2022 as tabulated by Zint (BSD-3-Clause License).
 */
final class RectangularMicroQrCode extends AbstractSymbology
{
    private const HEIGHTS = [7, 7, 7, 7, 7, 9, 9, 9, 9, 9, 11, 11, 11, 11, 11, 11, 13, 13, 13, 13, 13, 13, 15, 15, 15, 15, 15, 17, 17, 17, 17, 17];
    private const WIDTHS = [43, 59, 77, 99, 139, 43, 59, 77, 99, 139, 27, 43, 59, 77, 99, 139, 27, 43, 59, 77, 99, 139, 43, 59, 77, 99, 139, 43, 59, 77, 99, 139];

    /** Data codewords, indexed [M, H][version index]. */
    private const DATA_CODEWORDS = [
        'M' => [6, 12, 20, 28, 44, 12, 21, 31, 42, 63, 7, 19, 31, 43, 57, 84, 12, 27, 38, 53, 73, 106, 33, 48, 67, 88, 127, 39, 56, 78, 100, 152],
        'H' => [3, 7, 10, 14, 24, 7, 11, 17, 22, 33, 5, 11, 15, 23, 29, 42, 7, 13, 20, 29, 35, 54, 15, 26, 31, 48, 69, 21, 28, 38, 56, 76],
    ];

    private const TOTAL_CODEWORDS = [13, 21, 32, 44, 68, 21, 33, 49, 66, 99, 15, 31, 47, 67, 89, 132, 21, 41, 60, 85, 113, 166, 51, 74, 103, 136, 199, 61, 88, 122, 160, 232];

    private const BLOCKS = [
        'M' => [1, 1, 1, 1, 1, 1, 1, 1, 1, 2, 1, 1, 1, 1, 2, 2, 1, 1, 1, 2, 2, 3, 1, 1, 2, 2, 3, 1, 2, 2, 3, 4],
        'H' => [1, 1, 1, 1, 2, 1, 1, 2, 2, 3, 1, 1, 2, 2, 2, 3, 1, 1, 2, 2, 3, 4, 2, 2, 3, 4, 5, 2, 2, 3, 4, 6],
    ];

    /** Character count indicator widths per mode and version index. */
    private const COUNT_BITS = [
        'N' => [4, 5, 6, 7, 7, 5, 6, 7, 7, 8, 4, 6, 7, 7, 8, 8, 5, 6, 7, 7, 8, 8, 7, 7, 8, 8, 9, 7, 8, 8, 8, 9],
        'A' => [3, 5, 5, 6, 6, 5, 5, 6, 6, 7, 4, 5, 6, 6, 7, 7, 5, 6, 6, 7, 7, 8, 6, 7, 7, 7, 8, 6, 7, 7, 8, 8],
        'B' => [3, 4, 5, 5, 6, 4, 5, 5, 6, 6, 3, 5, 5, 6, 6, 7, 4, 5, 6, 6, 7, 7, 6, 6, 7, 7, 7, 6, 6, 7, 7, 8],
        'K' => [2, 3, 4, 5, 5, 3, 4, 5, 5, 6, 2, 4, 5, 5, 6, 6, 3, 5, 5, 6, 6, 7, 5, 5, 6, 6, 7, 5, 6, 6, 6, 7],
    ];

    /** Alignment pattern centre columns per symbol width (Table D.1). */
    private const ALIGNMENT_COLUMNS = [27 => [], 43 => [21], 59 => [19, 39], 77 => [25, 51], 99 => [23, 49, 75], 139 => [27, 55, 83, 111]];

    private const FORMAT_MASK_LEFT = 0x1FAB2;
    private const FORMAT_MASK_RIGHT = 0x20A7B;
    private const ECI_UTF8 = 26;
    private const GS = "\x1D";

    /**
     * @param ErrorCorrectionLevel $errorCorrection M (default) or H.
     * @param string|null          $size            Force a size such as "R11x43"; null picks the smallest area that fits.
     * @param int|null             $height          Restrict the automatic choice to one height (7, 9, 11, 13, 15 or 17).
     * @param bool|null            $eci             Emit ECI 26 (UTF-8): null = only for non-ASCII UTF-8 text.
     * @param bool                 $gs1             GS1 mode: ASCII GS (0x1D) after variable-length fields.
     * @param bool                 $kanji           Allow Kanji mode for Japanese characters.
     */
    public function __construct(
        private readonly ErrorCorrectionLevel $errorCorrection = ErrorCorrectionLevel::M,
        private readonly ?string $size = null,
        private readonly ?int $height = null,
        private readonly ?bool $eci = null,
        private readonly bool $gs1 = false,
        private readonly bool $kanji = true,
    ) {
        if ($errorCorrection !== ErrorCorrectionLevel::M && $errorCorrection !== ErrorCorrectionLevel::H) {
            throw new \InvalidArgumentException('rMQR supports error correction levels M and H only.');
        }
        if ($size !== null && self::versionIndex($size) === null) {
            throw new \InvalidArgumentException(sprintf('Unknown rMQR size "%s"; sizes are named like "R11x43".', $size));
        }
        if ($height !== null && !in_array($height, self::HEIGHTS, true)) {
            throw new \InvalidArgumentException('rMQR heights are 7, 9, 11, 13, 15 or 17 modules.');
        }
        if ($gs1 && $eci === true) {
            throw new \InvalidArgumentException('GS1 symbols cannot carry an ECI.');
        }
    }

    /**
     * Every available size, e.g. ["R7x43", "R7x59", …].
     *
     * @return list<string>
     */
    public static function sizes(): array
    {
        return array_map(static fn(int $h, int $w): string => sprintf('R%dx%d', $h, $w), self::HEIGHTS, self::WIDTHS);
    }

    public function name(): string
    {
        return 'rMQR Code';
    }

    public function quietZone(): int
    {
        return 2;
    }

    protected function normalize(string $data): string
    {
        return $this->gs1 ? ltrim($data, self::GS) : $data;
    }

    protected function validate(string $data): void
    {
        if ($this->gs1) {
            foreach (str_split($data) as $position => $character) {
                $byte = ord($character);
                if ($byte > 127 || ($byte < 32 && $character !== self::GS)) {
                    throw InvalidDataException::invalidCharacter($this->name() . ' (GS1)', $character, $position);
                }
            }
        }
    }

    protected function doEncode(string $data): MatrixSymbol
    {
        [$useEci, $units] = $this->plan($data);
        $level = $this->levelKey();
        $header = ($useEci ? 11 : 0) + ($this->gs1 ? 3 : 0);

        $best = null;
        foreach ($this->candidates() as $index) {
            $segments = Segmenter::optimize(
                $units,
                [Mode::Numeric, Mode::Alphanumeric, Mode::Byte, Mode::Kanji],
                static fn(Mode $mode): int => 3 + self::countBits($mode, $index),
            );
            if ($segments === null) {
                continue;
            }
            $bits = $header;
            foreach ($segments as $segment) {
                if ($segment->count >= 1 << self::countBits($segment->mode, $index)) {
                    continue 2;
                }
                $bits += 3 + self::countBits($segment->mode, $index) + $segment->dataBits();
            }
            if ($bits <= self::DATA_CODEWORDS[$level][$index] * 8) {
                $area = self::HEIGHTS[$index] * self::WIDTHS[$index];
                if ($best === null || $area < $best[2]) {
                    $best = [$index, $segments, $area];
                }
            }
        }

        if ($best === null) {
            throw new InvalidDataException(sprintf(
                'The data is too long for an rMQR Code at error correction level %s%s.',
                $level,
                $this->size !== null ? ' in size ' . $this->size : ($this->height !== null ? ' at height ' . $this->height : ''),
            ));
        }

        [$index, $segments] = $best;
        $codewords = $this->codewords($this->dataStream($segments, $index, $useEci), $index);
        $grid = $this->buildMatrix($codewords, $index);

        return new MatrixSymbol(
            $grid->rows(),
            (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $data),
            sprintf('%s R%dx%d', $this->name(), self::HEIGHTS[$index], self::WIDTHS[$index]),
            $this->quietZone(),
            1,
            [['row' => 0, 'column' => 0, 'size' => 7]],
        );
    }

    /**
     * @return list<int> Version indexes (0-31) to consider.
     */
    private function candidates(): array
    {
        if ($this->size !== null) {
            return [(int) self::versionIndex($this->size)];
        }

        return array_keys(array_filter(self::HEIGHTS, fn(int $h): bool => $this->height === null || $h === $this->height));
    }

    private static function versionIndex(string $size): ?int
    {
        if (preg_match('/^R?(\d+)\s*[xX×]\s*(\d+)$/u', trim($size), $m) !== 1) {
            return null;
        }
        foreach (self::HEIGHTS as $index => $height) {
            if ($height === (int) $m[1] && self::WIDTHS[$index] === (int) $m[2]) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return 'M'|'H'
     */
    private function levelKey(): string
    {
        return $this->errorCorrection === ErrorCorrectionLevel::H ? 'H' : 'M';
    }

    private static function countBits(Mode $mode, int $index): int
    {
        return self::COUNT_BITS[match ($mode) {
            Mode::Numeric => 'N',
            Mode::Alphanumeric => 'A',
            Mode::Byte => 'B',
            Mode::Kanji => 'K',
        }][$index];
    }

    private static function indicator(Mode $mode): int
    {
        return match ($mode) {
            Mode::Numeric => 0b001,
            Mode::Alphanumeric => 0b010,
            Mode::Byte => 0b011,
            Mode::Kanji => 0b100,
        };
    }

    /**
     * Same character-set policy as QrCode: Kanji mode and ECI are never mixed.
     *
     * @return array{bool, list<array{bytes: string, byte: bool, numeric: bool, alnum: ?string, kanji: ?string}>}
     */
    private function plan(string $data): array
    {
        if ($this->gs1 || $this->eci === false) {
            return [false, Segmenter::units($data, $this->kanji, $this->gs1)];
        }
        if ($this->eci === true) {
            return [true, Segmenter::units($data, false, false)];
        }
        if (preg_match('/[\x80-\xFF]/', $data) !== 1 || preg_match('//u', $data) !== 1) {
            return [false, Segmenter::units($data, false, false)];
        }
        if ($this->kanji) {
            $units = Segmenter::units($data, true, false, nonAsciiBytes: false);
            $allKanji = true;
            foreach ($units as $unit) {
                if (!$unit['byte'] && $unit['kanji'] === null) {
                    $allKanji = false;
                    break;
                }
            }
            if ($allKanji) {
                return [false, $units];
            }
        }

        return [true, Segmenter::units($data, false, false)];
    }

    /**
     * @param list<Segment> $segments
     *
     * @return list<int>
     */
    private function dataStream(array $segments, int $index, bool $useEci): array
    {
        $buffer = new BitBuffer();
        if ($this->gs1) {
            $buffer->append(0b101, 3);
        }
        if ($useEci) {
            $buffer->append(0b111, 3)->append(self::ECI_UTF8, 8);
        }
        foreach ($segments as $segment) {
            $buffer->append(self::indicator($segment->mode), 3);
            $buffer->append($segment->count, self::countBits($segment->mode, $index));
            $segment->appendDataTo($buffer);
        }

        $capacity = self::DATA_CODEWORDS[$this->levelKey()][$index] * 8;
        $buffer->append(0, min(3, $capacity - count($buffer)));
        $buffer->append(0, (8 - count($buffer) % 8) % 8);
        for ($pad = 0xEC; count($buffer) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $buffer->append($pad, 8);
        }

        return $buffer->toWords();
    }

    /**
     * Splits into blocks, adds Reed–Solomon codewords, and interleaves.
     *
     * @param list<int> $data
     *
     * @return list<int>
     */
    private function codewords(array $data, int $index): array
    {
        $blockCount = self::BLOCKS[$this->levelKey()][$index];
        $dataCount = count($data);
        $eccPerBlock = intdiv(self::TOTAL_CODEWORDS[$index] - $dataCount, $blockCount);
        $shortLength = intdiv($dataCount, $blockCount);
        $shortBlocks = $blockCount - $dataCount % $blockCount;
        $rs = new ReedSolomon(GaloisField::qrCode());

        $dataBlocks = [];
        $eccBlocks = [];
        $offset = 0;
        for ($i = 0; $i < $blockCount; $i++) {
            $length = $shortLength + ($i >= $shortBlocks ? 1 : 0);
            $block = array_slice($data, $offset, $length);
            $offset += $length;
            $dataBlocks[] = $block;
            $eccBlocks[] = $rs->encode($block, $eccPerBlock);
        }

        $result = [];
        for ($i = 0; $i <= $shortLength; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $eccPerBlock; $i++) {
            foreach ($eccBlocks as $block) {
                $result[] = $block[$i];
            }
        }

        return $result;
    }

    /**
     * @param list<int> $codewords
     */
    private function buildMatrix(array $codewords, int $index): ModuleGrid
    {
        $width = self::WIDTHS[$index];
        $height = self::HEIGHTS[$index];
        $grid = new ModuleGrid($width, $height);

        // Timing patterns along all four edges.
        for ($x = 0; $x < $width; $x++) {
            $grid->setFunction($x, 0, $x % 2 === 0);
            $grid->setFunction($x, $height - 1, $x % 2 === 0);
        }
        for ($y = 0; $y < $height; $y++) {
            $grid->setFunction(0, $y, $y % 2 === 0);
            $grid->setFunction($width - 1, $y, $y % 2 === 0);
        }

        // Finder pattern (top left), without its separator.
        for ($y = 0; $y < 7; $y++) {
            for ($x = 0; $x < 7; $x++) {
                $distance = max(abs($x - 3), abs($y - 3));
                $grid->setFunction($x, $y, $distance !== 2);
            }
        }

        // Finder sub-pattern (bottom right): a 5×5 ring with a dark centre.
        for ($dy = 0; $dy < 5; $dy++) {
            for ($dx = 0; $dx < 5; $dx++) {
                $grid->setFunction($width - 5 + $dx, $height - 5 + $dy, max(abs($dx - 2), abs($dy - 2)) !== 1);
            }
        }

        // Corner finder patterns.
        $grid->setFunction(0, $height - 2, true);
        $grid->setFunction(1, $height - 2, false);
        $grid->setFunction(1, $height - 1, true);
        $grid->setFunction($width - 2, 0, true);
        $grid->setFunction($width - 2, 1, false);
        $grid->setFunction($width - 1, 1, true);

        // Separator to the right of and below the finder.
        for ($y = 0; $y < 7; $y++) {
            $grid->setFunction(7, $y, false);
        }
        if ($height > 7) {
            for ($x = 0; $x < 8; $x++) {
                $grid->setFunction($x, 7, false);
            }
        }

        // Alignment patterns: 3×3 rings at the top and bottom, joined by vertical timing.
        foreach (self::ALIGNMENT_COLUMNS[$width] as $column) {
            for ($y = 0; $y < $height; $y++) {
                $grid->setFunction($column, $y, $y % 2 === 0);
            }
            foreach ([1, 2, $height - 3, $height - 2] as $y) {
                $grid->setFunction($column - 1, $y, true);
                $grid->setFunction($column + 1, $y, true);
            }
        }

        $this->drawFormat($grid, $index, false); // reserve the area

        $bits = implode('', array_map(static fn(int $c): string => str_pad(decbin($c), 8, '0', STR_PAD_LEFT), $codewords));
        $grid->placeData($bits, null, $width - 2);

        // rMQR always uses the QR mask pattern 4.
        $grid = $grid->masked(Masks::qr(4));
        $this->drawFormat($grid, $index, true);

        return $grid;
    }

    private function drawFormat(ModuleGrid $grid, int $index, bool $real): void
    {
        $data = $index + ($this->errorCorrection === ErrorCorrectionLevel::H ? 32 : 0);
        $remainder = $data;
        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
        }
        $bch = $data << 12 | $remainder;
        $left = $real ? $bch ^ self::FORMAT_MASK_LEFT : 0;
        $right = $real ? $bch ^ self::FORMAT_MASK_RIGHT : 0;

        $width = $grid->width;
        $height = $grid->height;
        for ($i = 0; $i < 5; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $grid->setFunction(8 + $j, 1 + $i, (($left >> ($j * 5 + $i)) & 1) === 1);
                $grid->setFunction($width - 8 + $j, $height - 6 + $i, (($right >> ($j * 5 + $i)) & 1) === 1);
            }
        }
        for ($k = 0; $k < 3; $k++) {
            $grid->setFunction(11, 1 + $k, (($left >> (15 + $k)) & 1) === 1);
            $grid->setFunction($width - 5 + $k, $height - 6, (($right >> (15 + $k)) & 1) === 1);
        }
    }
}
