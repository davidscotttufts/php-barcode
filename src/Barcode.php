<?php

declare(strict_types=1);

namespace PhpBarcode;

use PhpBarcode\Exception\RenderException;
use PhpBarcode\Gs1\DigitalLink;
use PhpBarcode\Gs1\ElementString;
use PhpBarcode\Renderer\Color;
use PhpBarcode\Renderer\Orientation;
use PhpBarcode\Renderer\OutputFormat;
use PhpBarcode\Renderer\RendererInterface;
use PhpBarcode\Renderer\RenderOptions;
use PhpBarcode\Renderer\Style\FinderShape;
use PhpBarcode\Renderer\Style\Gradient;
use PhpBarcode\Renderer\Style\Logo;
use PhpBarcode\Renderer\Style\ModuleShape;
use PhpBarcode\Renderer\SvgRenderer;
use PhpBarcode\Symbol\Symbol;
use PhpBarcode\Symbology\AztecCode;
use PhpBarcode\Symbology\BearerBars;
use PhpBarcode\Symbology\Codabar;
use PhpBarcode\Symbology\Code128;
use PhpBarcode\Symbology\Code39;
use PhpBarcode\Symbology\Code93;
use PhpBarcode\Symbology\DataMatrix;
use PhpBarcode\Symbology\DataMatrix\DataMatrixShape;
use PhpBarcode\Symbology\Ean13;
use PhpBarcode\Symbology\Ean8;
use PhpBarcode\Symbology\Gs1;
use PhpBarcode\Symbology\Gs1128;
use PhpBarcode\Symbology\Interleaved2of5;
use PhpBarcode\Symbology\Isbn;
use PhpBarcode\Symbology\Itf14;
use PhpBarcode\Symbology\Pdf417;
use PhpBarcode\Symbology\QrCode;
use PhpBarcode\Symbology\QrCode\ErrorCorrectionLevel;
use PhpBarcode\Symbology\SymbologyInterface;
use PhpBarcode\Symbology\UpcA;
use PhpBarcode\Symbology\UpcE;
use PhpBarcode\Symbology\UspsIntelligentMail;

/**
 * Fluent entry point: encode once, then render to any format.
 *
 *     echo Barcode::code128('HELLO-123')->withBarHeight(80)->toSvg();
 *
 *     Barcode::of('ean13', '400638133393')->save('/tmp/ean.png');
 *
 * Instances are immutable; every with*() method returns a new copy.
 */
final readonly class Barcode implements \Stringable
{
    private function __construct(
        public Symbol $symbol,
        public RenderOptions $options,
    ) {}

    /**
     * Encodes $data with any symbology, including your own implementations.
     * Without options, the symbol's own defaults are used (2D codes get larger modules and no caption).
     */
    public static function create(SymbologyInterface $symbology, string $data, ?RenderOptions $options = null): self
    {
        $symbol = $symbology->encode($data);

        return new self($symbol, $options ?? $symbol->defaultRenderOptions());
    }

    /**
     * Encodes $data with a built-in type, given as an enum or a name like "code128" or "EAN-13".
     * Payload objects (WiFi, VCard, …) and other Stringables are accepted as data.
     */
    public static function of(BarcodeType|string $type, string|\Stringable $data, ?RenderOptions $options = null): self
    {
        $type = is_string($type) ? BarcodeType::fromName($type) : $type;

        return self::create($type->symbology(), (string) $data, $options);
    }

    // ------------------------------------------------------------- 2D codes

    /**
     * @param string|\Stringable $data Text, a URL, or a payload such as new WiFi(…) or new VCard(…).
     */
    public static function qrCode(string|\Stringable $data, ErrorCorrectionLevel|string $errorCorrection = ErrorCorrectionLevel::M): self
    {
        $level = is_string($errorCorrection) ? ErrorCorrectionLevel::fromName($errorCorrection) : $errorCorrection;

        return self::create(new QrCode($level), (string) $data);
    }

    public static function dataMatrix(string|\Stringable $data, DataMatrixShape $shape = DataMatrixShape::Square): self
    {
        return self::create(new DataMatrix($shape), (string) $data);
    }

    /**
     * @param int $errorCorrection Minimum percentage of the symbol used for error correction.
     */
    public static function aztec(string|\Stringable $data, int $errorCorrection = 23): self
    {
        return self::create(new AztecCode($errorCorrection), (string) $data);
    }

    public static function pdf417(string|\Stringable $data, ?int $errorCorrectionLevel = null): self
    {
        return self::create(new Pdf417($errorCorrectionLevel), (string) $data);
    }

    // ------------------------------------------------------------------ GS1

    /**
     * GS1-128 from element strings such as "(01)09506000134352(17)251231(10)ABC123".
     */
    public static function gs1128(string $elementString): self
    {
        return self::create(new Gs1128(), $elementString);
    }

    /**
     * GS1 DataMatrix, the standard for healthcare and many logistics labels.
     */
    public static function gs1DataMatrix(string $elementString): self
    {
        return self::create(new Gs1(new DataMatrix(gs1: true)), $elementString);
    }

    /**
     * A QR code holding a GS1 Digital Link URI, the GS1 successor to the retail
     * barcode: phones open the URL, and GS1-aware scanners read the GTIN.
     *
     *     Barcode::digitalLink('(01)09506000134352(10)ABC123')
     *     // → https://id.gs1.org/01/09506000134352/10/ABC123
     */
    public static function digitalLink(string|ElementString $elementString, string $domain = 'https://id.gs1.org'): self
    {
        return self::create(new QrCode(), (new DigitalLink($domain))->toUri($elementString));
    }

    public static function gs1QrCode(string $elementString): self
    {
        return self::create(new Gs1(new QrCode(gs1: true)), $elementString);
    }

    // --------------------------------------------------------------- retail

    /**
     * @param string $data 12 or 13 digits, optionally followed by a 2- or 5-digit add-on ("9781234567897+51299").
     */
    public static function ean13(string $data): self
    {
        return self::create(new Ean13(), $data);
    }

    public static function ean8(string $data): self
    {
        return self::create(new Ean8(), $data);
    }

    public static function upcA(string $data): self
    {
        return self::create(new UpcA(), $data);
    }

    public static function upcE(string $data): self
    {
        return self::create(new UpcE(), $data);
    }

    /**
     * @param string $isbn ISBN-13 or ISBN-10, with or without hyphens.
     */
    public static function isbn(string $isbn): self
    {
        return self::create(new Isbn(), $isbn);
    }

    public static function itf14(string $gtin, BearerBars $bearers = BearerBars::Box): self
    {
        return self::create(new Itf14($bearers), $gtin);
    }

    // ------------------------------------------------------ general purpose

    public static function code128(string $data): self
    {
        return self::create(new Code128(), $data);
    }

    public static function code39(string $data, bool $withChecksum = false): self
    {
        return self::create(new Code39($withChecksum), $data);
    }

    public static function code93(string $data, bool $fullAscii = false): self
    {
        return self::create(new Code93($fullAscii), $data);
    }

    public static function interleaved2of5(string $data, bool $withChecksum = false): self
    {
        return self::create(new Interleaved2of5($withChecksum), $data);
    }

    public static function codabar(string $data): self
    {
        return self::create(new Codabar(), $data);
    }

    // --------------------------------------------------------------- postal

    /**
     * USPS Intelligent Mail: 20-digit tracking code plus optional routing ZIP, e.g. "01234567094987654321-01234567891".
     */
    public static function intelligentMail(string $data): self
    {
        return self::create(new UspsIntelligentMail(), $data);
    }

    // ---------------------------------------------------------------- options

    public function withOptions(RenderOptions $options): self
    {
        return new self($this->symbol, $options);
    }

    public function withModuleWidth(float $pixels): self
    {
        return $this->withOptions($this->options->withModuleWidth($pixels));
    }

    public function withBarHeight(float $pixels): self
    {
        return $this->withOptions($this->options->withBarHeight($pixels));
    }

    public function withQuietZone(?int $modules): self
    {
        return $this->withOptions($this->options->withQuietZone($modules));
    }

    public function withText(bool $show = true, ?float $fontSize = null): self
    {
        return $this->withOptions($this->options->withText($show, $fontSize));
    }

    /**
     * Prints custom text under the barcode instead of the encoded data, e.g. a
     * product name or "Scan to pay". Works with every type, including 2D codes.
     */
    public function withCaption(string $caption): self
    {
        return $this->withOptions($this->options->withCaption($caption));
    }

    public function withoutText(): self
    {
        return $this->withOptions($this->options->withoutText());
    }

    public function withColors(Color|string $foreground, Color|string|null $background = null): self
    {
        return $this->withOptions($this->options->withColors($foreground, $background));
    }

    /**
     * Shape of the modules in 2D codes: square, rounded or dot.
     */
    public function withModuleShape(ModuleShape $shape): self
    {
        return $this->withOptions($this->options->withModuleShape($shape));
    }

    /**
     * Shape of the corner "eyes" of QR-style codes.
     */
    public function withFinderShape(FinderShape $shape): self
    {
        return $this->withOptions($this->options->withFinderShape($shape));
    }

    public function withGradient(Gradient $gradient): self
    {
        return $this->withOptions($this->options->withGradient($gradient));
    }

    /**
     * Places an image in the center of a 2D code. Pair it with high error correction.
     */
    public function withLogo(Logo|string $logo, float $size = 0.2): self
    {
        return $this->withOptions($this->options->withLogo(is_string($logo) ? Logo::fromFile($logo, $size) : $logo));
    }

    public function vertical(): self
    {
        return $this->withOptions($this->options->withOrientation(Orientation::Vertical));
    }

    public function horizontal(): self
    {
        return $this->withOptions($this->options->withOrientation(Orientation::Horizontal));
    }

    // -------------------------------------------------------------- rendering

    /**
     * @throws RenderException
     */
    public function render(RendererInterface|OutputFormat|string $format = OutputFormat::Svg): string
    {
        return self::rendererFor($format)->render($this->symbol, $this->options);
    }

    public function toSvg(): string
    {
        return $this->render(OutputFormat::Svg);
    }

    public function toPng(): string
    {
        return $this->render(OutputFormat::Png);
    }

    public function toText(): string
    {
        return $this->render(OutputFormat::Text);
    }

    /**
     * A data: URI for inline use, e.g. <img src="<?= $barcode->toDataUri() ?>">.
     */
    public function toDataUri(RendererInterface|OutputFormat|string $format = OutputFormat::Svg): string
    {
        $renderer = self::rendererFor($format);

        return sprintf('data:%s;base64,%s', $renderer->mimeType(), base64_encode($renderer->render($this->symbol, $this->options)));
    }

    /**
     * Writes the barcode to a file. The format is inferred from the extension unless given.
     *
     * @throws RenderException
     */
    public function save(string $path, RendererInterface|OutputFormat|string|null $format = null): void
    {
        $bytes = $this->render($format ?? OutputFormat::fromPath($path));

        if (@file_put_contents($path, $bytes) === false) {
            throw RenderException::cannotWrite($path);
        }
    }

    /**
     * Sends the barcode to the browser with the right Content-Type header.
     */
    public function output(RendererInterface|OutputFormat|string $format = OutputFormat::Png): void
    {
        $renderer = self::rendererFor($format);
        $bytes = $renderer->render($this->symbol, $this->options);

        if (!headers_sent()) {
            header('Content-Type: ' . $renderer->mimeType());
            header('Content-Length: ' . strlen($bytes));
        }

        echo $bytes;
    }

    /**
     * Inline SVG, so a barcode can be echoed straight into a template.
     */
    public function __toString(): string
    {
        return $this->render(new SvgRenderer(xmlDeclaration: false));
    }

    private static function rendererFor(RendererInterface|OutputFormat|string $format): RendererInterface
    {
        return match (true) {
            $format instanceof RendererInterface => $format,
            $format instanceof OutputFormat => $format->renderer(),
            default => OutputFormat::fromName($format)->renderer(),
        };
    }
}
