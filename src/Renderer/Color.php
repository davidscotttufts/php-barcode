<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer;

/**
 * An immutable RGBA color.
 */
final readonly class Color
{
    /**
     * @param int<0, 255> $red
     * @param int<0, 255> $green
     * @param int<0, 255> $blue
     * @param float       $alpha Opacity from 0.0 (transparent) to 1.0 (opaque).
     */
    public function __construct(
        public int $red,
        public int $green,
        public int $blue,
        public float $alpha = 1.0,
    ) {
        foreach ([$red, $green, $blue] as $channel) {
            if ($channel < 0 || $channel > 255) {
                throw new \InvalidArgumentException('Color channels must be between 0 and 255.');
            }
        }
        if ($alpha < 0.0 || $alpha > 1.0) {
            throw new \InvalidArgumentException('Alpha must be between 0.0 and 1.0.');
        }
    }

    /**
     * Parses "#rgb", "#rrggbb" or "#rrggbbaa" (the "#" is optional).
     */
    public static function fromHex(string $hex): self
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (!ctype_xdigit($hex) || (strlen($hex) !== 6 && strlen($hex) !== 8)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid hex color.', $hex));
        }

        /** @var array{int<0, 255>, int<0, 255>, int<0, 255>} $rgb */
        $rgb = array_map('hexdec', str_split(substr($hex, 0, 6), 2));
        $alpha = strlen($hex) === 8 ? hexdec(substr($hex, 6, 2)) / 255 : 1.0;

        return new self($rgb[0], $rgb[1], $rgb[2], round($alpha, 3));
    }

    public static function black(): self
    {
        return new self(0, 0, 0);
    }

    public static function white(): self
    {
        return new self(255, 255, 255);
    }

    public static function transparent(): self
    {
        return new self(255, 255, 255, 0.0);
    }

    public function toHex(): string
    {
        return sprintf('#%02x%02x%02x', $this->red, $this->green, $this->blue);
    }

    public function isOpaque(): bool
    {
        return $this->alpha >= 1.0;
    }

    /**
     * GD's alpha scale: 0 is opaque, 127 is fully transparent.
     *
     * @return int<0, 127>
     */
    public function gdAlpha(): int
    {
        /** @var int<0, 127> */
        return (int) round((1.0 - $this->alpha) * 127);
    }
}
