<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer;

use PhpBarcode\Exception\RenderException;
use PhpBarcode\Renderer\Canvas\GdCanvas;
use PhpBarcode\Renderer\Scene\SceneBuilder;
use PhpBarcode\Symbol\Symbol;

/**
 * Raster output via the GD extension. Subclasses only choose the file format.
 */
abstract class AbstractGdRenderer implements RendererInterface
{
    private ?SceneBuilder $scenes = null;

    final public function render(Symbol $symbol, RenderOptions $options): string
    {
        if (!extension_loaded('gd')) {
            throw RenderException::missingExtension('gd', static::class);
        }

        $scene = ($this->scenes ??= new SceneBuilder())->build($symbol, $options);

        return $scene->drawOn(new GdCanvas(
            $scene->width,
            $scene->height,
            $options,
            $scene->fillBox,
            $this->capture(...),
        ));
    }

    /**
     * Writes the image in this renderer's format to the output buffer.
     */
    abstract protected function encode(\GdImage $image): bool;

    private function capture(\GdImage $image): string
    {
        ob_start();
        try {
            $ok = $this->encode($image);
        } finally {
            $bytes = (string) ob_get_clean();
        }

        if (!$ok) {
            throw new RenderException(sprintf('GD failed to encode the image as %s.', $this->extension()));
        }

        return $bytes;
    }
}
