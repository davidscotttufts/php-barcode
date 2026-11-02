<?php

declare(strict_types=1);

namespace PhpBarcode\Symbol;

/**
 * Pixel geometry of a symbol in its unrotated orientation: the canvas size,
 * the dark rectangles, polygons and rings to fill, and human-readable text.
 *
 * Symbols produce a Layout; renderers turn Layouts into drawings. Matrix
 * symbols additionally describe their individual modules and finder
 * patterns so renderers can draw styled (dotted, rounded) codes.
 */
final readonly class Layout
{
    /**
     * @param list<array{x: float, y: float, width: float, height: float}> $rects
     *        Dark areas, merged where possible. Always sufficient to draw the symbol.
     * @param string|null $text     Single caption centered under the symbol (convenience for $texts).
     * @param float       $textTop  Top edge of the caption, in pixels.
     * @param list<array{text: string, x: float, y: float, size: float, anchor: 'start'|'middle'|'end'}> $texts
     *        Additional positioned text; y is the top edge of the text, x the anchor point.
     * @param list<array{x: float, y: float, size: float}> $modules
     *        Matrix symbols only: every dark module outside finder patterns, for styled rendering.
     * @param list<array{x: float, y: float, size: float}> $finders
     *        Matrix symbols only: finder pattern boxes (each 7×7 modules for QR), for styled rendering.
     * @param array{x: float, y: float, width: float, height: float}|null $symbolBox
     *        The symbol itself without its quiet zone, used to center logos.
     * @param list<list<array{float, float}>> $polygons
     *        Dark filled polygons as point lists, for non-rectangular modules (MaxiCode hexagons).
     * @param list<array{x: float, y: float, outer: float, inner: float}> $annuli
     *        Dark circular rings (inner 0 = a disc), for bullseye finders.
     */
    public function __construct(
        public float $width,
        public float $height,
        public array $rects,
        public ?string $text = null,
        public float $textTop = 0.0,
        public float $fontSize = 0.0,
        public array $texts = [],
        public array $modules = [],
        public array $finders = [],
        public ?array $symbolBox = null,
        public array $polygons = [],
        public array $annuli = [],
    ) {}

    /**
     * Every text item, with the single caption (if any) expanded into a positioned item.
     *
     * @return list<array{text: string, x: float, y: float, size: float, anchor: 'start'|'middle'|'end'}>
     */
    public function allTexts(): array
    {
        $texts = $this->texts;

        if ($this->text !== null) {
            $texts[] = ['text' => $this->text, 'x' => $this->width / 2, 'y' => $this->textTop, 'size' => $this->fontSize, 'anchor' => 'middle'];
        }

        return $texts;
    }
}
