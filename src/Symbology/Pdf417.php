<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\MatrixSymbol;
use PhpBarcode\Symbology\Pdf417\ErrorCorrection;
use PhpBarcode\Symbology\Pdf417\MacroSegment;
use PhpBarcode\Symbology\Pdf417\Tables;

/**
 * PDF417 (ISO/IEC 15438), the stacked 2D code used on driving licences,
 * boarding passes and shipping labels. Encodes up to about 1.1 KB of binary
 * data, 1.8 K text characters or 2.7 K digits.
 *
 * Data is compacted automatically (text, byte and numeric modes). UTF-8 input
 * is tagged with ECI 26 so readers decode it correctly.
 *
 *     new Pdf417();                                  // automatic size and error correction
 *     new Pdf417(errorCorrectionLevel: 5, columns: 6);
 *     new Pdf417(compact: true);                     // truncated PDF417, for tight spaces
 *     new Pdf417(macro: new MacroSegment(1, 3, '123456'));
 */
final class Pdf417 extends AbstractPdf417
{
    private const START = '11111111010101000';
    private const STOP = '111111101000101001';
    private const COMPACT_STOP = '1';

    private const MAX_CODEWORDS = 928;
    private const MIN_ROWS = 3;
    private const MAX_ROWS = 90;
    private const MAX_COLUMNS = 30;

    /**
     * @param int|null $errorCorrectionLevel 0-8 (2 to 512 error-correction codewords); null picks the
     *                                       level ISO/IEC 15438 recommends for the data length.
     * @param int|null $columns              Data columns (1-30); null chooses them from $aspectRatio.
     * @param int|null $rows                 Rows (3-90); null uses as few as possible.
     * @param float    $aspectRatio          Preferred width ÷ height when the size is chosen automatically.
     * @param int      $rowHeight            Row height in modules (ISO/IEC 15438 recommends at least 3).
     * @param bool     $compact              Compact (truncated) PDF417: drops the right row indicator and stop pattern.
     */
    public function __construct(
        private readonly ?int $errorCorrectionLevel = null,
        private readonly ?int $columns = null,
        private readonly ?int $rows = null,
        private readonly float $aspectRatio = 3.0,
        private readonly int $rowHeight = 3,
        private readonly bool $compact = false,
        int|false|null $eci = null,
        ?MacroSegment $macro = null,
    ) {
        parent::__construct($eci, $macro);

        if ($errorCorrectionLevel !== null) {
            ErrorCorrection::codewordsForLevel($errorCorrectionLevel); // validates the range
        }
        if ($columns !== null && ($columns < 1 || $columns > self::MAX_COLUMNS)) {
            throw new \InvalidArgumentException('PDF417 symbols have 1 to 30 data columns.');
        }
        if ($rows !== null && ($rows < self::MIN_ROWS || $rows > self::MAX_ROWS)) {
            throw new \InvalidArgumentException('PDF417 symbols have 3 to 90 rows.');
        }
        if ($aspectRatio <= 0) {
            throw new \InvalidArgumentException('The aspect ratio must be positive.');
        }
        if ($rowHeight < 1) {
            throw new \InvalidArgumentException('The row height must be at least one module.');
        }
    }

    public function name(): string
    {
        return $this->compact ? 'Compact PDF417' : 'PDF417';
    }

    public function quietZone(): int
    {
        return 2;
    }

    protected function doEncode(string $data): MatrixSymbol
    {
        $payload = [...$this->dataCodewords($data, startInByteMode: false), ...$this->macroCodewords()];
        $level = $this->chooseLevel(count($payload));
        $ecCount = ErrorCorrection::codewordsForLevel($level);

        // +1 for the symbol length descriptor that starts every PDF417 symbol.
        [$columns, $rows] = $this->dimensions(1 + count($payload) + $ecCount);

        $dataCount = $columns * $rows - $ecCount;
        $padding = $dataCount - 1 - count($payload);
        $macro = $this->macroCodewords();
        $content = array_slice($payload, 0, count($payload) - count($macro));

        // Padding goes between the data and the Macro PDF417 control block.
        $codewords = [$dataCount, ...$content, ...array_fill(0, $padding, 900), ...$macro];
        $codewords = [...$codewords, ...ErrorCorrection::encode($codewords, $ecCount)];

        return new MatrixSymbol(
            $this->rowsToModules($codewords, $columns, $rows, $level),
            $this->humanReadable($data),
            $this->name(),
            $this->quietZone(),
            $this->rowHeight,
        );
    }

    private function chooseLevel(int $dataCodewords): int
    {
        if ($this->errorCorrectionLevel !== null) {
            $level = $this->errorCorrectionLevel;
        } else {
            $level = match (true) {
                $dataCodewords <= 40 => 2,
                $dataCodewords <= 160 => 3,
                $dataCodewords <= 320 => 4,
                default => 5,
            };
            // Step down for very long messages rather than failing outright.
            while ($level > 0 && 1 + $dataCodewords + ErrorCorrection::codewordsForLevel($level) > self::MAX_CODEWORDS) {
                $level--;
            }
        }

        $total = 1 + $dataCodewords + ErrorCorrection::codewordsForLevel($level);
        if ($total > self::MAX_CODEWORDS) {
            throw new InvalidDataException(sprintf(
                'The data needs %d codewords at error-correction level %d, but %s holds at most %d.',
                $total,
                $level,
                $this->name(),
                self::MAX_CODEWORDS,
            ));
        }

        return $level;
    }

    /**
     * @return array{int, int} [columns, rows]
     */
    private function dimensions(int $needed): array
    {
        $fit = static fn(int $columns): int => max(self::MIN_ROWS, (int) ceil($needed / $columns));

        if ($this->columns !== null && $this->rows !== null) {
            if ($this->columns * $this->rows < $needed || $this->columns * $this->rows > self::MAX_CODEWORDS) {
                throw $this->tooLarge($needed, sprintf('%d columns × %d rows', $this->columns, $this->rows));
            }

            return [$this->columns, $this->rows];
        }

        if ($this->columns !== null) {
            $rows = $fit($this->columns);
            if ($rows > self::MAX_ROWS || $this->columns * $rows > self::MAX_CODEWORDS) {
                throw $this->tooLarge($needed, sprintf('%d columns', $this->columns));
            }

            return [$this->columns, $rows];
        }

        if ($this->rows !== null) {
            $columns = (int) ceil($needed / $this->rows);
            if ($columns > self::MAX_COLUMNS || $columns * $this->rows > self::MAX_CODEWORDS) {
                throw $this->tooLarge($needed, sprintf('%d rows', $this->rows));
            }

            return [$columns, $this->rows];
        }

        $best = null;
        $bestScore = INF;
        for ($columns = 1; $columns <= self::MAX_COLUMNS; $columns++) {
            $rows = $fit($columns);
            if ($rows > self::MAX_ROWS || $columns * $rows > self::MAX_CODEWORDS) {
                continue;
            }
            $ratio = $this->moduleWidth($columns) / ($rows * $this->rowHeight);
            $score = abs(log($ratio / $this->aspectRatio)) + ($columns * $rows - $needed) / 1000;
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = [$columns, $rows];
            }
        }

        return $best ?? throw $this->tooLarge($needed, 'any size');
    }

    private function moduleWidth(int $columns): int
    {
        // Start (17) + left row indicator (17) + data + right row indicator (17) + stop (18).
        return 17 * $columns + ($this->compact ? 35 : 69);
    }

    private function tooLarge(int $needed, string $constraint): InvalidDataException
    {
        return new InvalidDataException(sprintf('The data needs %d codewords, which does not fit %s.', $needed, $constraint));
    }

    /**
     * @param list<int> $codewords
     *
     * @return list<string>
     */
    private function rowsToModules(array $codewords, int $columns, int $rows, int $level): array
    {
        $lines = [];
        for ($row = 0; $row < $rows; $row++) {
            $base = intdiv($row, 3) * 30;
            [$left, $right] = match ($row % 3) {
                0 => [$base + intdiv($rows - 1, 3), $base + $columns - 1],
                1 => [$base + $level * 3 + ($rows - 1) % 3, $base + intdiv($rows - 1, 3)],
                default => [$base + $columns - 1, $base + $level * 3 + ($rows - 1) % 3],
            };

            $line = self::START . Tables::codeword($left, $row);
            foreach (array_slice($codewords, $row * $columns, $columns) as $codeword) {
                $line .= Tables::codeword($codeword, $row);
            }
            $line .= $this->compact ? self::COMPACT_STOP : Tables::codeword($right, $row) . self::STOP;

            $lines[] = $line;
        }

        return $lines;
    }
}
