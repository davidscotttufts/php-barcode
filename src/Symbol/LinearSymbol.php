<?php

declare(strict_types=1);

namespace PhpBarcode\Symbol;

use PhpBarcode\Renderer\RenderOptions;

/**
 * A one-dimensional (linear) barcode.
 *
 * Stored as a run-length list of module widths that alternate bar, space,
 * bar, space, … and always starts and ends with a bar. One module is the
 * narrowest possible element; renderers decide how many pixels it occupies.
 *
 * Optional presentation details support the classic retail look and other
 * conventions:
 *  - guard bars that extend down into the text area (EAN/UPC),
 *  - human-readable text split into positioned segments, below the bars,
 *    above the whole symbol (ISBN line) or above add-on bars,
 *  - an add-on (EAN-2/EAN-5) whose bars start lower to make room for its text,
 *  - bearer bars (ITF-14), either top and bottom or a full box,
 *  - different left and right quiet zones.
 *
 * @phpstan-type TextSegment array{text: string, x: float, anchor: 'start'|'middle'|'end', position: 'below'|'above'|'addon'}
 */
final readonly class LinearSymbol implements Symbol
{
    /** @var non-empty-list<positive-int> */
    public array $widths;

    /** @var list<TextSegment> */
    public array $textSegments;

    /**
     * @param list<int>             $widths         Alternating bar/space widths in modules, starting with a bar.
     * @param string                $text           Human-readable text (also the default caption).
     * @param string                $symbology      Display name of the symbology that produced this barcode.
     * @param int                   $quietZone      Blank margin on the left (and right, unless $rightQuietZone is set), in modules.
     * @param int|null              $rightQuietZone Blank margin on the right, in modules.
     * @param list<array{int, int}> $guards         Module ranges [start, end) whose bars extend into the text area.
     * @param list<array{text: string, x: float|int, anchor?: 'start'|'middle'|'end', position?: 'below'|'above'|'addon'}> $textSegments
     *        Positioned text replacing the centered caption; x is in modules from the first bar (may be negative).
     * @param int|null              $addOnStart     Module offset where an add-on symbol begins.
     * @param int                   $bearerWidth    Bearer bar thickness in modules (0 = none).
     * @param bool                  $bearerBox      Draw bearer bars on all four sides instead of top and bottom only.
     */
    public function __construct(
        array $widths,
        private string $text,
        private string $symbology,
        private int $quietZone = 10,
        public ?int $rightQuietZone = null,
        public array $guards = [],
        array $textSegments = [],
        public ?int $addOnStart = null,
        public int $bearerWidth = 0,
        public bool $bearerBox = false,
    ) {
        if ($widths === []) {
            throw new \InvalidArgumentException('A barcode needs at least one bar.');
        }
        if (count($widths) % 2 === 0) {
            throw new \InvalidArgumentException('A barcode must start and end with a bar (odd number of elements).');
        }
        foreach ($widths as $width) {
            if (!is_int($width) || $width < 1) {
                throw new \InvalidArgumentException('Every element width must be a positive integer.');
            }
        }
        if ($quietZone < 0 || ($rightQuietZone !== null && $rightQuietZone < 0)) {
            throw new \InvalidArgumentException('The quiet zone cannot be negative.');
        }
        if ($bearerWidth < 0) {
            throw new \InvalidArgumentException('The bearer bar width cannot be negative.');
        }

        /** @var non-empty-list<positive-int> $widths */
        $this->widths = array_values($widths);
        $this->textSegments = array_values(array_map(static fn(array $segment): array => [
            'text' => $segment['text'],
            'x' => (float) $segment['x'],
            'anchor' => $segment['anchor'] ?? 'middle',
            'position' => $segment['position'] ?? 'below',
        ], $textSegments));
    }

    /**
     * Builds a barcode from a string of single-digit widths, e.g. "211214".
     */
    public static function fromWidthString(string $pattern, string $text, string $symbology, int $quietZone = 10): self
    {
        if (!ctype_digit($pattern)) {
            throw new \InvalidArgumentException('A width pattern may only contain digits.');
        }

        return new self(array_map('intval', str_split($pattern)), $text, $symbology, $quietZone);
    }

    /**
     * Builds a barcode from a module bitmap, e.g. "1011001" where 1 is a bar.
     */
    public static function fromBinary(string $bits, string $text, string $symbology, int $quietZone = 10): self
    {
        return new self(self::runLengths($bits), $text, $symbology, $quietZone);
    }

    /**
     * Converts a module bitmap such as "1011001" into run lengths [1, 1, 2, 2, 1].
     *
     * @return list<int>
     */
    public static function runLengths(string $bits): array
    {
        if ($bits === '' || strspn($bits, '01') !== strlen($bits)) {
            throw new \InvalidArgumentException('A binary pattern may only contain 0 and 1.');
        }
        if ($bits[0] !== '1' || $bits[-1] !== '1') {
            throw new \InvalidArgumentException('A binary pattern must start and end with a bar (1).');
        }

        preg_match_all('/1+|0+/', $bits, $runs);

        return array_map('strlen', $runs[0]);
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

    // ------------------------------------------------------------ copies

    public function withSymbology(string $symbology): self
    {
        return $this->copy(symbology: $symbology);
    }

    public function withText(string $text): self
    {
        return $this->copy(text: $text);
    }

    /**
     * @param list<array{text: string, x: float|int, anchor?: 'start'|'middle'|'end', position?: 'below'|'above'|'addon'}> $segments
     */
    public function withTextSegments(array $segments): self
    {
        return $this->copy(textSegments: $segments);
    }

    /**
     * Adds a line of text centered above the whole symbol (e.g. "ISBN 978-…").
     */
    public function withHeading(string $heading): self
    {
        // Center over the main symbol, ignoring any add-on.
        $mainWidth = 0;
        foreach ($this->bars() as [$offset, $width]) {
            if ($this->addOnStart === null || $offset < $this->addOnStart) {
                $mainWidth = $offset + $width;
            }
        }

        $segments = $this->textSegments;
        if ($segments === []) {
            $segments[] = ['text' => $this->text, 'x' => $mainWidth / 2, 'anchor' => 'middle', 'position' => 'below'];
        }
        $segments[] = ['text' => $heading, 'x' => $mainWidth / 2, 'anchor' => 'middle', 'position' => 'above'];

        return $this->copy(textSegments: $segments);
    }

    public function withQuietZones(int $left, ?int $right = null): self
    {
        return $this->copy(quietZone: $left, rightQuietZone: $right);
    }

    public function withBearers(int $width, bool $box = false): self
    {
        return $this->copy(bearerWidth: $width, bearerBox: $box);
    }

    /**
     * Appends an add-on symbol (EAN-2/EAN-5) after a gap of $gap modules.
     * The add-on's bars start lower and its text is printed above them.
     */
    public function withAddOn(self $addOn, int $gap = 9): self
    {
        if ($gap < 1) {
            throw new \InvalidArgumentException('The add-on gap must be at least one module.');
        }

        $offset = $this->totalModules() + $gap;
        $segments = $this->textSegments === []
            ? [['text' => $this->text, 'x' => $this->totalModules() / 2, 'anchor' => 'middle', 'position' => 'below']]
            : $this->textSegments;
        foreach ($addOn->textSegments as $segment) {
            $segments[] = ['text' => $segment['text'], 'x' => $segment['x'] + $offset, 'anchor' => $segment['anchor'], 'position' => 'addon'];
        }
        if ($addOn->textSegments === []) {
            $segments[] = ['text' => $addOn->text, 'x' => $offset + $addOn->totalModules() / 2, 'anchor' => 'middle', 'position' => 'addon'];
        }

        return new self(
            [...$this->widths, $gap, ...$addOn->widths],
            $this->text . ' ' . $addOn->text,
            $this->symbology,
            $this->quietZone,
            $addOn->rightQuietZone ?? $addOn->quietZone,
            $this->guards,
            $segments,
            $offset,
            $this->bearerWidth,
            $this->bearerBox,
        );
    }

    // --------------------------------------------------------- geometry

    /**
     * Total width of the symbol in modules, excluding quiet zones.
     */
    public function totalModules(): int
    {
        return array_sum($this->widths);
    }

    /**
     * Yields every dark bar as [offset, width], both measured in modules.
     *
     * @return \Generator<int, array{int, int}>
     */
    public function bars(): \Generator
    {
        $offset = 0;
        foreach ($this->widths as $index => $width) {
            if ($index % 2 === 0) {
                yield [$offset, $width];
            }
            $offset += $width;
        }
    }

    /**
     * The symbol as a module bitmap, e.g. "11010010000".
     */
    public function toBinary(): string
    {
        $bits = '';
        foreach ($this->widths as $index => $width) {
            $bits .= str_repeat($index % 2 === 0 ? '1' : '0', $width);
        }

        return $bits;
    }

    public function toGrid(): array
    {
        return [$this->toBinary()];
    }

    public function layout(RenderOptions $options): Layout
    {
        $module = $options->moduleWidth;
        $left = $options->quietZone ?? $this->quietZone;
        $right = $options->quietZone ?? $this->rightQuietZone ?? $this->quietZone;
        $bearer = $this->bearerWidth * $module;
        $boxInset = $this->bearerBox ? $bearer : 0.0;

        $segments = $this->textSegments;
        $showText = $options->showText && ($segments !== [] || trim($this->text) !== '');

        // Retail digits must fit between the guard bars: about 7 modules per digit.
        $fontSize = $segments === [] ? $options->fontSize : min($options->fontSize, 10 * $module);
        $lineHeight = $fontSize * 1.25;
        $gap = $options->textGap;

        $has = static fn(string $position): bool => $showText
            && array_filter($segments, static fn(array $s): bool => $s['position'] === $position) !== [];
        $hasBelow = $showText && ($segments === [] || $has('below'));

        $topBand = $has('above') ? $lineHeight + $gap : 0.0;
        $barTop = $topBand + $bearer;
        $barBottom = $barTop + $options->barHeight;
        $guardExtension = $hasBelow && $this->guards !== [] ? $gap + $fontSize * 0.5 : 0.0;
        $addOnTop = $barTop + ($has('addon') ? $lineHeight : 0.0);

        $rects = [];
        foreach ($this->bars() as [$offset, $width]) {
            $x = $boxInset + ($left + $offset) * $module;
            if ($this->addOnStart !== null && $offset >= $this->addOnStart) {
                $top = $addOnTop;
                $bottom = $barBottom + $guardExtension;
            } else {
                $top = $barTop;
                $bottom = $barBottom + ($this->isGuard($offset, $width) ? $guardExtension : 0.0);
            }
            $rects[] = ['x' => $x, 'y' => $top, 'width' => $width * $module, 'height' => $bottom - $top];
        }

        $width = ($left + $this->totalModules() + $right) * $module + 2 * $boxInset;

        if ($bearer > 0) {
            $rects[] = ['x' => 0.0, 'y' => $topBand, 'width' => $width, 'height' => $bearer];
            $rects[] = ['x' => 0.0, 'y' => $barBottom, 'width' => $width, 'height' => $bearer];
            if ($this->bearerBox) {
                $rects[] = ['x' => 0.0, 'y' => $topBand, 'width' => $bearer, 'height' => $barBottom + $bearer - $topBand];
                $rects[] = ['x' => $width - $bearer, 'y' => $topBand, 'width' => $bearer, 'height' => $barBottom + $bearer - $topBand];
            }
        }

        $textTop = $barBottom + $bearer + $gap;
        $height = $barBottom + $bearer + ($hasBelow ? $gap + $lineHeight : 0.0);

        if ($segments === []) {
            return new Layout(
                width: $width,
                height: $height,
                rects: $rects,
                text: $showText ? $this->text : null,
                textTop: $textTop,
                fontSize: $fontSize,
            );
        }

        $texts = [];
        if ($showText) {
            foreach ($segments as $segment) {
                $texts[] = [
                    'text' => $segment['text'],
                    'x' => $boxInset + ($left + $segment['x']) * $module,
                    'y' => match ($segment['position']) {
                        'above' => 0.0,
                        'addon' => $barTop,
                        'below' => $textTop,
                    },
                    'size' => $fontSize,
                    'anchor' => $segment['anchor'],
                ];
            }
        }

        return new Layout(width: $width, height: $height, rects: $rects, texts: $texts);
    }

    public function defaultRenderOptions(): RenderOptions
    {
        return new RenderOptions();
    }

    private function isGuard(int $offset, int $width): bool
    {
        foreach ($this->guards as [$start, $end]) {
            if ($offset < $end && $offset + $width > $start) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{text: string, x: float|int, anchor?: 'start'|'middle'|'end', position?: 'below'|'above'|'addon'}>|null $textSegments
     */
    private function copy(
        ?string $text = null,
        ?string $symbology = null,
        ?int $quietZone = null,
        int|false|null $rightQuietZone = false,
        ?array $textSegments = null,
        ?int $bearerWidth = null,
        ?bool $bearerBox = null,
    ): self {
        return new self(
            $this->widths,
            $text ?? $this->text,
            $symbology ?? $this->symbology,
            $quietZone ?? $this->quietZone,
            $rightQuietZone === false ? $this->rightQuietZone : $rightQuietZone,
            $this->guards,
            $textSegments ?? $this->textSegments,
            $this->addOnStart,
            $bearerWidth ?? $this->bearerWidth,
            $bearerBox ?? $this->bearerBox,
        );
    }
}
