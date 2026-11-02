<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer;

use PhpBarcode\Exception\RenderException;
use PhpBarcode\Symbol\Symbol;

/**
 * Turns any encoded symbol into bytes in some output format.
 */
interface RendererInterface
{
    /**
     * @throws RenderException
     */
    public function render(Symbol $symbol, RenderOptions $options): string;

    /**
     * MIME type of the rendered output, e.g. "image/svg+xml".
     */
    public function mimeType(): string;

    /**
     * Conventional file extension without the dot, e.g. "svg".
     */
    public function extension(): string;
}
