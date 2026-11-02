<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer\Style;

/**
 * An image placed in the center of a 2D symbol.
 *
 * Logos hide modules, which the symbol must recover through error
 * correction: use a high error-correction level (QR level H tolerates about
 * 30% damage) and keep the logo small. Linear symbols ignore logos.
 */
final readonly class Logo
{
    /**
     * @param string $data     Raw image bytes (PNG, JPEG, GIF, WebP or SVG).
     * @param string $mimeType MIME type of $data.
     * @param float  $size     Logo width as a fraction of the symbol width (0.05–0.4).
     * @param bool   $punchout Clear the modules behind the logo, plus a margin, for a clean look.
     * @param float  $margin   Clear space around the logo when punching out, in modules.
     */
    public function __construct(
        public string $data,
        public string $mimeType,
        public float $size = 0.2,
        public bool $punchout = true,
        public float $margin = 1.0,
    ) {
        if ($data === '') {
            throw new \InvalidArgumentException('The logo image is empty.');
        }
        if ($size < 0.05 || $size > 0.4) {
            throw new \InvalidArgumentException('The logo size must be between 0.05 and 0.4 of the symbol width; larger logos make codes unreadable.');
        }
        if ($margin < 0) {
            throw new \InvalidArgumentException('The logo margin cannot be negative.');
        }
    }

    public static function fromFile(string $path, float $size = 0.2, bool $punchout = true, float $margin = 1.0): self
    {
        $data = is_file($path) ? file_get_contents($path) : false;
        if ($data === false) {
            throw new \InvalidArgumentException(sprintf('Cannot read logo file "%s".', $path));
        }

        return new self($data, self::detectMimeType($data), $size, $punchout, $margin);
    }

    /**
     * Width / height of the image.
     */
    public function aspectRatio(): float
    {
        if ($this->mimeType === 'image/svg+xml') {
            if (preg_match('/viewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $this->data, $m) === 1 && (float) $m[2] > 0) {
                return (float) $m[1] / (float) $m[2];
            }

            return 1.0;
        }

        $info = @getimagesizefromstring($this->data);

        return $info !== false && $info[1] > 0 ? $info[0] / $info[1] : 1.0;
    }

    private static function detectMimeType(string $data): string
    {
        return match (true) {
            str_starts_with($data, "\x89PNG") => 'image/png',
            str_starts_with($data, "\xFF\xD8") => 'image/jpeg',
            str_starts_with($data, 'GIF8') => 'image/gif',
            str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP' => 'image/webp',
            str_contains(substr($data, 0, 1024), '<svg') => 'image/svg+xml',
            default => throw new \InvalidArgumentException('Unsupported logo format; use PNG, JPEG, GIF, WebP or SVG.'),
        };
    }
}
