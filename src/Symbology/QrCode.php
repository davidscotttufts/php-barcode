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
use PhpBarcode\Symbology\QrCode\QrSpec;
use PhpBarcode\Symbology\QrCode\Segment;
use PhpBarcode\Symbology\QrCode\Segmenter;
use PhpBarcode\Symbology\QrCode\StructuredAppend;

/**
 * QR Code Model 2 (ISO/IEC 18004), versions 1-40.
 *
 * - Picks the smallest version that fits, with the cheapest mix of numeric,
 *   alphanumeric, byte and Kanji segments.
 * - UTF-8 text is marked with ECI 26 so readers decode it correctly.
 * - Chooses the mask with the lowest penalty score, unless one is forced.
 * - Supports GS1 (FNC1 in first position) and Structured Append.
 *
 * Tables and algorithms follow ISO/IEC 18004:2015, with reference to Project
 * Nayuki's QR Code generator (MIT License).
 */
final class QrCode extends AbstractSymbology
{
    private const ECI_UTF8 = 26;
    private const GS = "\x1D";

    /**
     * @param ErrorCorrectionLevel $errorCorrection      Minimum error correction level.
     * @param int|null             $version              Force a version (1-40); null picks the smallest that fits.
     * @param int                  $minVersion           Smallest version to consider when picking automatically.
     * @param int|null             $mask                 Force a mask pattern (0-7); null picks the best.
     * @param bool|null            $eci                  Emit ECI 26 (UTF-8): null = only for non-ASCII UTF-8 text.
     * @param bool                 $gs1                  GS1 mode: data is an element string, with ASCII GS (0x1D) after variable-length fields.
     * @param bool                 $kanji                Allow Kanji mode for Japanese characters.
     * @param bool                 $boostErrorCorrection Raise the error correction level when it fits in the same version.
     */
    public function __construct(
        private readonly ErrorCorrectionLevel $errorCorrection = ErrorCorrectionLevel::M,
        private readonly ?int $version = null,
        private readonly int $minVersion = QrSpec::MIN_VERSION,
        private readonly ?int $mask = null,
        private readonly ?bool $eci = null,
        private readonly bool $gs1 = false,
        private readonly bool $kanji = true,
        private readonly bool $boostErrorCorrection = false,
        private readonly ?StructuredAppend $structuredAppend = null,
    ) {
        foreach ([$version, $minVersion] as $v) {
            if ($v !== null && ($v < QrSpec::MIN_VERSION || $v > QrSpec::MAX_VERSION)) {
                throw new \InvalidArgumentException('QR Code versions range from 1 to 40.');
            }
        }
        if ($mask !== null && ($mask < 0 || $mask > 7)) {
            throw new \InvalidArgumentException('QR Code mask patterns are numbered 0-7.');
        }
        if ($gs1 && $eci === true) {
            throw new \InvalidArgumentException('GS1 QR Codes cannot carry an ECI.');
        }
    }

    public function name(): string
    {
        return 'QR Code';
    }

    public function quietZone(): int
    {
        return 4;
    }

    protected function normalize(string $data): string
    {
        // In GS1 mode the leading FNC1 is implied by the mode indicator.
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
        [$useEci, $units] = $this->planCharacterSet($data);

        [$version, $segments, $bits] = $this->chooseVersion($units, $useEci, $data);

        $level = $this->errorCorrection;
        if ($this->boostErrorCorrection) {
            while (($stronger = $level->stronger()) !== null && $bits <= QrSpec::dataCodewords($version, $stronger) * 8) {
                $level = $stronger;
            }
        }

        $buffer = $this->bitStream($segments, $version, $level, $useEci);
        $codewords = $this->addErrorCorrection($buffer->toWords(), $version, $level);
        $grid = $this->buildMatrix($codewords, $version, $level);

        $size = QrSpec::size($version);

        return new MatrixSymbol(
            $grid->rows(),
            (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $data),
            $this->name(),
            $this->quietZone(),
            1,
            [
                ['row' => 0, 'column' => 0, 'size' => 7],
                ['row' => 0, 'column' => $size - 7, 'size' => 7],
                ['row' => $size - 7, 'column' => 0, 'size' => 7],
            ],
        );
    }

    /**
     * Decides between UTF-8 bytes with ECI 26 and Shift JIS Kanji mode.
     *
     * Many readers (zxing among them) apply an ECI to Kanji segments too, so
     * the two are never mixed: text whose non-ASCII characters are all Kanji
     * uses Kanji mode without an ECI; anything else uses UTF-8 with an ECI.
     *
     * @return array{bool, list<array{bytes: string, byte: bool, numeric: bool, alnum: ?string, kanji: ?string}>}
     */
    private function planCharacterSet(string $data): array
    {
        if ($this->gs1 || $this->eci === false) {
            return [false, Segmenter::units($data, $this->kanji, $this->gs1)];
        }
        if ($this->eci === true) {
            return [true, Segmenter::units($data, false, false)];
        }

        $isUtf8 = preg_match('//u', $data) === 1;
        if (preg_match('/[\x80-\xFF]/', $data) !== 1 || !$isUtf8) {
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
     * @param list<array{bytes: string, byte: bool, numeric: bool, alnum: ?string, kanji: ?string}> $units
     *
     * @return array{int, list<Segment>, int} version, segments, total data bits
     */
    private function chooseVersion(array $units, bool $useEci, string $data): array
    {
        $header = ($this->structuredAppend === null ? 0 : 20) + ($useEci ? 12 : 0) + ($this->gs1 ? 4 : 0);
        $modes = [Mode::Numeric, Mode::Alphanumeric, Mode::Byte, Mode::Kanji];
        $candidates = $this->version === null ? range($this->minVersion, QrSpec::MAX_VERSION) : [$this->version];

        $cache = [];
        foreach ($candidates as $version) {
            $group = $version <= 9 ? 9 : ($version <= 26 ? 26 : 40);
            $segments = $cache[$group] ??= Segmenter::optimize(
                $units,
                $modes,
                static fn(Mode $mode): int => 4 + $mode->characterCountBits($version),
            );
            if ($segments === null) {
                throw new InvalidDataException('QR Code cannot encode this data.');
            }

            $bits = $header;
            foreach ($segments as $segment) {
                if ($segment->count >= 1 << $segment->mode->characterCountBits($version)) {
                    continue 2;
                }
                $bits += 4 + $segment->mode->characterCountBits($version) + $segment->dataBits();
            }

            if ($bits <= QrSpec::dataCodewords($version, $this->errorCorrection) * 8) {
                return [$version, $segments, $bits];
            }
        }

        throw new InvalidDataException(sprintf(
            'The data (%d bytes) is too long for a QR Code at error correction level %s%s.',
            strlen($data),
            $this->errorCorrection->value,
            $this->version === null ? '' : ' in version ' . $this->version,
        ));
    }

    /**
     * @param list<Segment> $segments
     */
    private function bitStream(array $segments, int $version, ErrorCorrectionLevel $level, bool $useEci): BitBuffer
    {
        $buffer = new BitBuffer();

        if ($this->structuredAppend !== null) {
            $buffer->append(0b0011, 4)
                ->append($this->structuredAppend->index, 4)
                ->append($this->structuredAppend->total - 1, 4)
                ->append($this->structuredAppend->parity, 8);
        }
        if ($useEci) {
            $buffer->append(0b0111, 4)->append(self::ECI_UTF8, 8);
        }
        if ($this->gs1) {
            $buffer->append(0b0101, 4);
        }

        foreach ($segments as $segment) {
            $buffer->append($segment->mode->indicator(), 4);
            $buffer->append($segment->count, $segment->mode->characterCountBits($version));
            $segment->appendDataTo($buffer);
        }

        $capacity = QrSpec::dataCodewords($version, $level) * 8;
        $buffer->append(0, min(4, $capacity - count($buffer)));
        $buffer->append(0, (8 - count($buffer) % 8) % 8);
        for ($pad = 0xEC; count($buffer) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $buffer->append($pad, 8);
        }

        return $buffer;
    }

    /**
     * Splits data into blocks, appends Reed–Solomon codewords and interleaves.
     *
     * @param list<int> $data
     *
     * @return list<int>
     */
    private function addErrorCorrection(array $data, int $version, ErrorCorrectionLevel $level): array
    {
        $blockCount = QrSpec::blocks($version, $level);
        $eccLength = QrSpec::eccCodewordsPerBlock($version, $level);
        $raw = QrSpec::totalCodewords($version);
        $shortBlocks = $blockCount - $raw % $blockCount;
        $shortLength = intdiv($raw, $blockCount);
        $rs = new ReedSolomon(GaloisField::qrCode());

        $blocks = [];
        $offset = 0;
        for ($i = 0; $i < $blockCount; $i++) {
            $length = $shortLength - $eccLength + ($i < $shortBlocks ? 0 : 1);
            $block = array_slice($data, $offset, $length);
            $offset += $length;
            $ecc = $rs->encode($block, $eccLength);
            if ($i < $shortBlocks) {
                $block[] = -1; // placeholder so all blocks align during interleaving
            }
            $blocks[] = [...$block, ...$ecc];
        }

        $result = [];
        for ($i = 0, $width = count($blocks[0]); $i < $width; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortLength - $eccLength || $j >= $shortBlocks) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    /**
     * @param list<int> $codewords
     */
    private function buildMatrix(array $codewords, int $version, ErrorCorrectionLevel $level): ModuleGrid
    {
        $size = QrSpec::size($version);
        $grid = new ModuleGrid($size, $size);

        for ($i = 0; $i < $size; $i++) {
            $grid->setFunction(6, $i, $i % 2 === 0);
            $grid->setFunction($i, 6, $i % 2 === 0);
        }

        $grid->drawFinder(3, 3);
        $grid->drawFinder($size - 4, 3);
        $grid->drawFinder(3, $size - 4);

        $alignment = QrSpec::alignmentPositions($version);
        $last = count($alignment) - 1;
        foreach ($alignment as $i => $cx) {
            foreach ($alignment as $j => $cy) {
                $nearFinder = ($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0);
                if (!$nearFinder) {
                    $grid->drawAlignment($cx, $cy);
                }
            }
        }

        self::drawFormatBits($grid, $level, 0); // reserve the area
        self::drawVersion($grid, $version);

        $bits = implode('', array_map(static fn(int $c): string => str_pad(decbin($c), 8, '0', STR_PAD_LEFT), $codewords));
        $grid->placeData($bits, 6);

        $best = null;
        $bestPenalty = PHP_INT_MAX;
        foreach ($this->mask === null ? range(0, 7) : [$this->mask] as $mask) {
            $candidate = $grid->masked(Masks::qr($mask));
            self::drawFormatBits($candidate, $level, $mask);
            $penalty = $this->mask === null ? Masks::penalty($candidate) : 0;
            if ($penalty < $bestPenalty) {
                $best = $candidate;
                $bestPenalty = $penalty;
            }
        }
        \assert($best instanceof ModuleGrid);

        return $best;
    }

    private static function drawFormatBits(ModuleGrid $grid, ErrorCorrectionLevel $level, int $mask): void
    {
        $data = $level->formatBits() << 3 | $mask;
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }
        $bits = ($data << 10 | $remainder) ^ 0x5412;
        $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;
        $size = $grid->width;

        for ($i = 0; $i <= 5; $i++) {
            $grid->setFunction(8, $i, $bit($i));
        }
        $grid->setFunction(8, 7, $bit(6));
        $grid->setFunction(8, 8, $bit(7));
        $grid->setFunction(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $grid->setFunction(14 - $i, 8, $bit($i));
        }

        for ($i = 0; $i < 8; $i++) {
            $grid->setFunction($size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $grid->setFunction(8, $size - 15 + $i, $bit($i));
        }
        $grid->setFunction(8, $size - 8, true); // the "dark module"
    }

    private static function drawVersion(ModuleGrid $grid, int $version): void
    {
        if ($version < 7) {
            return;
        }

        $remainder = $version;
        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
        }
        $bits = $version << 12 | $remainder;

        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            $a = $grid->width - 11 + $i % 3;
            $b = intdiv($i, 3);
            $grid->setFunction($a, $b, $dark);
            $grid->setFunction($b, $a, $dark);
        }
    }
}
