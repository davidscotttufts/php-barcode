<?php

declare(strict_types=1);

namespace PhpBarcode\Symbol;

use PhpBarcode\Renderer\RenderOptions;

/**
 * An encoded barcode, independent of any output format.
 *
 * Each kind of symbol (linear bars, a 2D module matrix, height-modulated
 * postal bars, …) knows how to lay itself out, so every renderer can draw
 * every symbol without knowing which kind it is.
 */
interface Symbol
{
    /**
     * Human-readable interpretation, e.g. the digits printed under an EAN-13.
     */
    public function text(): string;

    /**
     * Display name of the symbology, e.g. "QR Code".
     */
    public function symbology(): string;

    /**
     * Recommended blank margin on every side, in modules.
     */
    public function quietZone(): int;

    /**
     * Pixel geometry for the given options, in horizontal orientation.
     */
    public function layout(RenderOptions $options): Layout;

    /**
     * The symbol as rows of "1" (dark) and "0" (light) modules, without the quiet zone.
     * Linear symbols return a single row.
     *
     * @return non-empty-list<string>
     */
    public function toGrid(): array;

    /**
     * Sensible default presentation for this kind of symbol.
     */
    public function defaultRenderOptions(): RenderOptions;
}
