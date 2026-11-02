<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer;

use PhpBarcode\Renderer\Canvas\PdfCanvas;
use PhpBarcode\Renderer\Scene\SceneBuilder;
use PhpBarcode\Symbol\Symbol;

/**
 * Vector PDF output, one page sized exactly to the symbol, with no PDF library required.
 *
 * Page size follows the options' DPI (96 by default), so use
 * RenderOptions::forPrint() to get exact physical dimensions.
 */
final class PdfRenderer implements RendererInterface
{
    public function __construct(
        private readonly SceneBuilder $scenes = new SceneBuilder(),
    ) {}

    public function render(Symbol $symbol, RenderOptions $options): string
    {
        $scene = $this->scenes->build($symbol, $options);

        return $scene->drawOn(new PdfCanvas($scene->width, $scene->height, $options, $symbol->symbology() . ': ' . $symbol->text()));
    }

    public function mimeType(): string
    {
        return 'application/pdf';
    }

    public function extension(): string
    {
        return 'pdf';
    }
}
