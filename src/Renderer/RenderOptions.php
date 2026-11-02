<?php

declare(strict_types=1);

namespace PhpBarcode\Renderer;

use PhpBarcode\Renderer\Style\FinderShape;
use PhpBarcode\Renderer\Style\Gradient;
use PhpBarcode\Renderer\Style\Logo;
use PhpBarcode\Renderer\Style\ModuleShape;

/**
 * Immutable presentation settings shared by every renderer.
 *
 * Use the with*() methods to derive modified copies:
 *
 *     $options = (new RenderOptions())->withBarHeight(80)->withModuleWidth(3);
 */
final readonly class RenderOptions
{
    /**
     * @param float       $moduleWidth Width of the narrowest bar, in pixels.
     * @param float       $barHeight   Height of the bars, in pixels.
     * @param int|null    $quietZone   Blank margin on each side, in modules; null uses the symbology's recommendation.
     * @param bool        $showText    Print the human-readable text beneath the bars.
     * @param float       $fontSize    Text height in pixels.
     * @param float       $textGap     Space between the bars and the text, in pixels.
     * @param string|null $fontFile    Path to a TrueType font for raster output; null uses GD's built-in font.
     * @param ModuleShape $moduleShape Shape of 2D modules: square, rounded or dot.
     * @param FinderShape $finderShape Shape of 2D finder patterns ("eyes").
     * @param Gradient|null $gradient  Gradient fill for the dark modules, overriding $foreground.
     * @param Logo|null   $logo        Image centered on a 2D symbol.
     * @param string|null $caption     Custom text printed under the symbol instead of the encoded data
     *                                 (shown even on 2D codes). null prints the data, as controlled by $showText.
     * @param int|null    $dpi         Output resolution. Sets the physical size of SVG, PDF and EPS output and the
     *                                 resolution stored in raster files. null means 96 DPI (CSS pixels).
     */
    public function __construct(
        public float $moduleWidth = 2.0,
        public float $barHeight = 60.0,
        public ?int $quietZone = null,
        public bool $showText = true,
        public float $fontSize = 14.0,
        public float $textGap = 4.0,
        public Color $foreground = new Color(0, 0, 0),
        public Color $background = new Color(255, 255, 255),
        public Orientation $orientation = Orientation::Horizontal,
        public ?string $fontFile = null,
        public ModuleShape $moduleShape = ModuleShape::Square,
        public FinderShape $finderShape = FinderShape::Square,
        public ?Gradient $gradient = null,
        public ?Logo $logo = null,
        public ?int $dpi = null,
        public ?string $caption = null,
    ) {
        if ($moduleWidth <= 0) {
            throw new \InvalidArgumentException('The module width must be greater than zero.');
        }
        if ($barHeight <= 0) {
            throw new \InvalidArgumentException('The bar height must be greater than zero.');
        }
        if ($quietZone !== null && $quietZone < 0) {
            throw new \InvalidArgumentException('The quiet zone cannot be negative.');
        }
        if ($fontSize <= 0 || $textGap < 0) {
            throw new \InvalidArgumentException('The font size must be positive and the text gap non-negative.');
        }
        if ($fontFile !== null && !is_file($fontFile)) {
            throw new \InvalidArgumentException(sprintf('Font file "%s" does not exist.', $fontFile));
        }
        if ($dpi !== null && ($dpi < 1 || $dpi > 9600)) {
            throw new \InvalidArgumentException('The DPI must be between 1 and 9600.');
        }
    }

    /**
     * Options sized in millimetres for print.
     *
     *     RenderOptions::forPrint(moduleMm: 0.33, barHeightMm: 22.85, dpi: 600) // EAN-13 at 100%
     *
     * @param float $moduleMm    The "X-dimension": width of the narrowest bar, in millimetres.
     * @param float $barHeightMm Bar height in millimetres (ignored by 2D symbols).
     */
    public static function forPrint(float $moduleMm, float $barHeightMm = 15.0, int $dpi = 300): self
    {
        $pixelsPerMm = $dpi / 25.4;

        return new self(
            moduleWidth: $moduleMm * $pixelsPerMm,
            barHeight: $barHeightMm * $pixelsPerMm,
            fontSize: 2.75 * $pixelsPerMm,
            textGap: 0.5 * $pixelsPerMm,
            dpi: $dpi,
        );
    }

    /**
     * Resolution used to convert pixels to physical units.
     */
    public function effectiveDpi(): int
    {
        return $this->dpi ?? 96;
    }

    public function withModuleWidth(float $moduleWidth): self
    {
        return $this->with(moduleWidth: $moduleWidth);
    }

    public function withBarHeight(float $barHeight): self
    {
        return $this->with(barHeight: $barHeight);
    }

    public function withQuietZone(?int $quietZone): self
    {
        return $this->with(quietZone: $quietZone);
    }

    public function withText(bool $showText = true, ?float $fontSize = null): self
    {
        return $this->with(showText: $showText, fontSize: $fontSize ?? $this->fontSize);
    }

    public function withoutText(): self
    {
        return $this->with(showText: false, caption: null);
    }

    public function withTextGap(float $textGap): self
    {
        return $this->with(textGap: $textGap);
    }

    public function withColors(Color|string $foreground, Color|string|null $background = null): self
    {
        return $this->with(
            foreground: is_string($foreground) ? Color::fromHex($foreground) : $foreground,
            background: is_string($background) ? Color::fromHex($background) : ($background ?? $this->background),
        );
    }

    public function withOrientation(Orientation $orientation): self
    {
        return $this->with(orientation: $orientation);
    }

    public function withFontFile(?string $fontFile): self
    {
        return $this->with(fontFile: $fontFile);
    }

    public function withModuleShape(ModuleShape $shape): self
    {
        return $this->with(moduleShape: $shape);
    }

    public function withFinderShape(FinderShape $shape): self
    {
        return $this->with(finderShape: $shape);
    }

    public function withGradient(?Gradient $gradient): self
    {
        return $this->with(gradient: $gradient);
    }

    public function withLogo(?Logo $logo): self
    {
        return $this->with(logo: $logo);
    }

    /**
     * Prints custom text under the symbol instead of the encoded data; null restores the default.
     */
    public function withCaption(?string $caption): self
    {
        return $this->with(caption: $caption);
    }

    public function withDpi(?int $dpi): self
    {
        return $this->with(dpi: $dpi);
    }

    /**
     * Copies this object, replacing the given named properties.
     */
    private function with(mixed ...$changes): self
    {
        /** @var array<string, mixed> $properties */
        $properties = get_object_vars($this);

        // @phpstan-ignore argument.type (named arguments are validated by the constructor)
        return new self(...array_replace($properties, $changes));
    }
}
