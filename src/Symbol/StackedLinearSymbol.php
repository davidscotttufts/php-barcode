<?php

declare(strict_types=1);

namespace PhpBarcode\Symbol;

use PhpBarcode\Renderer\RenderOptions;

/**
 * A linear symbol drawn as several rows of different heights, such as
 * GS1 DataBar Stacked, Truncated and Expanded Stacked, whose data rows are
 * separated by thin separator patterns.
 *
 * Each row has a height in modules (X). Fixed rows always use exactly that
 * height. Flexible rows treat it as a minimum and grow proportionally so
 * that the whole symbol is as tall as RenderOptions::$barHeight.
 */
final readonly class StackedLinearSymbol implements Symbol
{
    /** @var non-empty-list<array{bits: string, height: float, flexible: bool}> */
    public array $rows;

    private int $width;

    /**
     * @param list<array{bits: string, height: float|int, flexible?: bool}> $rows
     *        Module bitmaps ("1" = bar), top to bottom; shorter rows are padded with spaces.
     */
    public function __construct(
        array $rows,
        private string $text,
        private string $symbology,
        private int $quietZone = 1,
    ) {
        if ($rows === []) {
            throw new \InvalidArgumentException('A stacked symbol needs at least one row.');
        }

        $width = max(array_map(static fn(array $row): int => strlen($row['bits']), $rows));
        $normalized = [];
        foreach ($rows as $row) {
            if (strspn($row['bits'], '01') !== strlen($row['bits']) || $row['height'] <= 0) {
                throw new \InvalidArgumentException('Rows must be strings of 0 and 1 with a positive height.');
            }
            $normalized[] = [
                'bits' => str_pad($row['bits'], $width, '0'),
                'height' => (float) $row['height'],
                'flexible' => $row['flexible'] ?? false,
            ];
        }
        if ($width === 0 || $quietZone < 0) {
            throw new \InvalidArgumentException('Rows must not be empty and the quiet zone cannot be negative.');
        }

        $this->rows = $normalized;
        $this->width = $width;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function symbology(): string
    {
        return $this->symbology;
    }

    public function quietZone(): int
    {
        return $this->quietZone;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function layout(RenderOptions $options): Layout
    {
        $quietZone = $options->quietZone ?? $this->quietZone;
        $module = $options->moduleWidth;

        $fixed = 0.0;
        $flexible = 0.0;
        foreach ($this->rows as $row) {
            $row['flexible'] ? $flexible += $row['height'] * $module : $fixed += $row['height'] * $module;
        }
        $scale = $flexible > 0.0 ? max(1.0, ($options->barHeight - $fixed) / $flexible) : 1.0;

        $rects = [];
        $y = 0.0;
        foreach ($this->rows as $row) {
            $height = $row['height'] * $module * ($row['flexible'] ? $scale : 1.0);
            preg_match_all('/1+/', $row['bits'], $runs, PREG_OFFSET_CAPTURE);
            foreach ($runs[0] as [$run, $x]) {
                $rects[] = ['x' => ($quietZone + $x) * $module, 'y' => $y, 'width' => strlen($run) * $module, 'height' => $height];
            }
            $y += $height;
        }

        $text = $options->showText && trim($this->text) !== '' ? $this->text : null;

        return new Layout(
            width: ($this->width + 2 * $quietZone) * $module,
            height: $y + ($text === null ? 0.0 : $options->textGap + $options->fontSize * 1.25),
            rects: $rects,
            text: $text,
            textTop: $y + $options->textGap,
            fontSize: $options->fontSize,
        );
    }

    /**
     * Each row repeated by its height in modules (rounded, at least once).
     */
    public function toGrid(): array
    {
        return array_merge(...array_map(
            static fn(array $row): array => array_fill(0, max(1, (int) round($row['height'])), $row['bits']),
            $this->rows,
        ));
    }

    public function defaultRenderOptions(): RenderOptions
    {
        return new RenderOptions();
    }
}
