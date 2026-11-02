<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer;

use PhpBarcode\Renderer\Canvas\SvgCanvas;
use PhpBarcode\Renderer\Scene\SceneBuilder;
use PhpBarcode\Symbol\Symbol;

/**
 * Scalable vector output with no extension requirements.
 *
 * Supports every styling option (module shapes, gradients, SVG or raster
 * logos) and stays crisp at any zoom level or print resolution. When the
 * options carry a DPI, width and height are written in millimetres.
 */
final class SvgRenderer implements RendererInterface
{
    public function __construct(
        private readonly string $fontFamily = 'ui-monospace, Menlo, Consolas, monospace',
        private readonly bool $xmlDeclaration = true,
        private readonly SceneBuilder $scenes = new SceneBuilder(),
    ) {}

    public function render(Symbol $symbol, RenderOptions $options): string
    {
        $scene = $this->scenes->build($symbol, $options);

        return $scene->drawOn(new SvgCanvas(
            $scene->width,
            $scene->height,
            $options,
            $scene->fillBox,
            $symbol->symbology() . ': ' . $symbol->text(),
            $this->fontFamily,
            $this->xmlDeclaration,
        ));
    }

    public function mimeType(): string
    {
        return 'image/svg+xml';
    }

    public function extension(): string
    {
        return 'svg';
    }
}
