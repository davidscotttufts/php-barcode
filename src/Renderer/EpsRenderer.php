<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer;

use PhpBarcode\Renderer\Canvas\EpsCanvas;
use PhpBarcode\Renderer\Scene\SceneBuilder;
use PhpBarcode\Symbol\Symbol;

/**
 * Encapsulated PostScript output for professional print layout software.
 */
final class EpsRenderer implements RendererInterface
{
    public function __construct(
        private readonly SceneBuilder $scenes = new SceneBuilder(),
    ) {}

    public function render(Symbol $symbol, RenderOptions $options): string
    {
        $scene = $this->scenes->build($symbol, $options);

        return $scene->drawOn(new EpsCanvas($scene->width, $scene->height, $options, $symbol->symbology() . ': ' . $symbol->text()));
    }

    public function mimeType(): string
    {
        return 'application/postscript';
    }

    public function extension(): string
    {
        return 'eps';
    }
}
