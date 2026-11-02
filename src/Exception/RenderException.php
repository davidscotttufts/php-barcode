<?php

declare(strict_types=1);

namespace PhpBarcode\Exception;

/**
 * Thrown when a barcode cannot be rendered or written.
 */
class RenderException extends \RuntimeException implements BarcodeException
{
    public static function missingExtension(string $extension, string $renderer): self
    {
        return new self(sprintf(
            'The "%s" PHP extension is required by %s. Install it, or use the SvgRenderer, which has no dependencies.',
            $extension,
            $renderer,
        ));
    }

    public static function unsupportedFormat(string $format): self
    {
        return new self(sprintf('Unsupported output format "%s".', $format));
    }

    public static function cannotWrite(string $path): self
    {
        return new self(sprintf('Unable to write barcode to "%s".', $path));
    }
}
