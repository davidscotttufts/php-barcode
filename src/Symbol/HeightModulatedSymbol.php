<?php

declare(strict_types=1);

namespace PhpBarcode\Symbol;

use PhpBarcode\Renderer\RenderOptions;

/**
 * A height-modulated barcode: equally spaced bars whose information lies in
 * their vertical extent rather than their width (POSTNET, PLANET, Intelligent
 * Mail, RM4SCC, KIX, Australia Post, Japan Post, Mailmark).
 *
 * Vertical geometry is expressed as fractions of the full bar height: the
 * tracker occupies [$trackerTop, $trackerBottom]; an ascender runs from the top
 * down to $trackerBottom; a descender from $trackerTop to the bottom. Two-state
 * codes such as POSTNET use Full bars and bottom-aligned Tracker bars
 * ($trackerBottom = 1.0).
 */
final readonly class HeightModulatedSymbol implements Symbol
{
    /** Rows used by toGrid(), enough to show the four shapes distinctly. */
    private const GRID_ROWS = 8;

    /** @var non-empty-list<BarState> */
    public array $bars;

    /**
     * @param list<BarState> $bars
     * @param int            $barWidth   Width of each bar, in modules.
     * @param int            $spaceWidth Width of the gap between bars, in modules.
     */
    public function __construct(
        array $bars,
        private string $text,
        private string $symbology,
        private int $quietZone = 6,
        public float $trackerTop = 0.375,
        public float $trackerBottom = 0.625,
        public int $barWidth = 1,
        public int $spaceWidth = 1,
    ) {
        if ($bars === []) {
            throw new \InvalidArgumentException('A height-modulated barcode needs at least one bar.');
        }
        foreach ($bars as $bar) {
            if (!$bar instanceof BarState) {
                throw new \InvalidArgumentException('Bars must be BarState values.');
            }
        }
        if ($trackerTop < 0.0 || $trackerBottom > 1.0 || $trackerTop >= $trackerBottom) {
            throw new \InvalidArgumentException('The tracker must satisfy 0 <= top < bottom <= 1.');
        }
        if ($barWidth < 1 || $spaceWidth < 1 || $quietZone < 0) {
            throw new \InvalidArgumentException('Bar and space widths must be positive and the quiet zone non-negative.');
        }

        $this->bars = array_values($bars);
    }

    /**
     * Builds a symbol from DAFT notation, e.g. "FADT".
     */
    public static function fromStates(
        string $states,
        string $text,
        string $symbology,
        int $quietZone = 6,
        float $trackerTop = 0.375,
        float $trackerBottom = 0.625,
    ): self {
        $bars = [];
        foreach (str_split(strtoupper($states)) as $position => $letter) {
            $bars[] = BarState::tryFrom($letter)
                ?? throw new \InvalidArgumentException(sprintf('Invalid bar state "%s" at position %d; use F, A, D or T.', $letter, $position));
        }

        return new self($bars, $text, $symbology, $quietZone, $trackerTop, $trackerBottom);
    }

    /**
     * The bars in DAFT notation, e.g. "FADT…".
     */
    public function toStates(): string
    {
        return implode('', array_map(static fn(BarState $bar): string => $bar->value, $this->bars));
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

    /**
     * Total width in modules, excluding quiet zones.
     */
    public function totalModules(): int
    {
        return count($this->bars) * ($this->barWidth + $this->spaceWidth) - $this->spaceWidth;
    }

    public function layout(RenderOptions $options): Layout
    {
        $quietZone = $options->quietZone ?? $this->quietZone;
        $module = $options->moduleWidth;
        $height = $options->barHeight;
        $pitch = $this->barWidth + $this->spaceWidth;

        $rects = [];
        foreach ($this->bars as $index => $bar) {
            [$top, $bottom] = $this->extent($bar);
            $rects[] = [
                'x' => ($quietZone + $index * $pitch) * $module,
                'y' => $top * $height,
                'width' => $this->barWidth * $module,
                'height' => ($bottom - $top) * $height,
            ];
        }

        $text = $options->showText && trim($this->text) !== '' ? $this->text : null;
        $textBlock = $text === null ? 0.0 : $options->textGap + $options->fontSize * 1.25;

        return new Layout(
            width: ($this->totalModules() + 2 * $quietZone) * $module,
            height: $height + $textBlock,
            rects: $rects,
            text: $text,
            textTop: $height + $options->textGap,
            fontSize: $options->fontSize,
        );
    }

    public function toGrid(): array
    {
        $rows = [];
        for ($row = 0; $row < self::GRID_ROWS; $row++) {
            $center = ($row + 0.5) / self::GRID_ROWS;
            $line = '';
            foreach ($this->bars as $index => $bar) {
                [$top, $bottom] = $this->extent($bar);
                if ($index > 0) {
                    $line .= str_repeat('0', $this->spaceWidth);
                }
                $line .= str_repeat($center >= $top && $center < $bottom ? '1' : '0', $this->barWidth);
            }
            $rows[] = $line;
        }

        return $rows;
    }

    /**
     * Postal codes are drawn wider-spaced and short, without a caption by default.
     */
    public function defaultRenderOptions(): RenderOptions
    {
        return new RenderOptions(moduleWidth: 2.0, barHeight: 24.0, showText: false);
    }

    /**
     * Vertical extent of a bar as [top, bottom] fractions of the full height.
     *
     * @return array{float, float}
     */
    private function extent(BarState $bar): array
    {
        return [
            $bar->hasAscender() ? 0.0 : $this->trackerTop,
            $bar->hasDescender() ? 1.0 : $this->trackerBottom,
        ];
    }
}
