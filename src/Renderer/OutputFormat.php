<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer;

use PhpBarcode\Exception\RenderException;

/**
 * The built-in output formats, each backed by a renderer.
 */
enum OutputFormat: string
{
    case Svg = 'svg';
    case Png = 'png';
    case Jpeg = 'jpeg';
    case Gif = 'gif';
    case Webp = 'webp';
    case Pdf = 'pdf';
    case Eps = 'eps';
    case Text = 'text';

    /**
     * Resolves a format name or file extension, e.g. "PNG", "jpg" or "txt".
     *
     * @throws RenderException
     */
    public static function fromName(string $name): self
    {
        $name = strtolower(trim($name));

        return match ($name) {
            'jpg' => self::Jpeg,
            'txt' => self::Text,
            'ps' => self::Eps,
            default => self::tryFrom($name) ?? throw RenderException::unsupportedFormat($name),
        };
    }

    /**
     * @throws RenderException
     */
    public static function fromPath(string $path): self
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($extension === '') {
            throw new RenderException(sprintf('Cannot infer an output format from "%s"; it has no extension.', $path));
        }

        return self::fromName($extension);
    }

    public function renderer(): RendererInterface
    {
        return match ($this) {
            self::Svg => new SvgRenderer(),
            self::Png => new PngRenderer(),
            self::Jpeg => new JpegRenderer(),
            self::Gif => new GifRenderer(),
            self::Webp => new WebpRenderer(),
            self::Pdf => new PdfRenderer(),
            self::Eps => new EpsRenderer(),
            self::Text => new TextRenderer(),
        };
    }
}
