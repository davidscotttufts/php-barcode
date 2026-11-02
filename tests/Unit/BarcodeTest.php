<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit;

use PhpBarcode\Barcode;
use PhpBarcode\BarcodeType;
use PhpBarcode\Exception\BarcodeException;
use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Exception\UnknownSymbologyException;
use PhpBarcode\Renderer\OutputFormat;
use PhpBarcode\Renderer\RenderOptions;
use PhpBarcode\Symbology\Code128;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BarcodeTest extends TestCase
{
    public function testFluentOptionsAreImmutable(): void
    {
        $original = Barcode::code128('ABC');
        $taller = $original->withBarHeight(120)->withModuleWidth(3);

        self::assertSame(60.0, $original->options->barHeight);
        self::assertSame(120.0, $taller->options->barHeight);
        self::assertSame(3.0, $taller->options->moduleWidth);
        self::assertSame($original->symbol, $taller->symbol);
    }

    public function testTypeNamesAreForgiving(): void
    {
        self::assertSame(BarcodeType::Ean13, BarcodeType::fromName('EAN-13'));
        self::assertSame(BarcodeType::Ean13, BarcodeType::fromName('ean_13'));
        self::assertSame(BarcodeType::Interleaved2of5, BarcodeType::fromName('code25'));
    }

    public function testUnknownTypeListsKnownTypes(): void
    {
        $this->expectException(UnknownSymbologyException::class);
        $this->expectExceptionMessageMatches('/Known types: .*code128/');

        Barcode::of('nope', '123');
    }

    public function testEveryLibraryErrorIsABarcodeException(): void
    {
        try {
            Barcode::ean13('12345');
            self::fail('Expected an exception.');
        } catch (BarcodeException $e) {
            self::assertInstanceOf(InvalidDataException::class, $e);
        }
    }

    /**
     * @param non-empty-string $magic
     */
    #[DataProvider('formats')]
    public function testRendersEveryFormat(OutputFormat $format, string $magic): void
    {
        if ($format !== OutputFormat::Svg && $format !== OutputFormat::Text && $format !== OutputFormat::Pdf && $format !== OutputFormat::Eps && !extension_loaded('gd')) {
            self::markTestSkipped('GD is not available.');
        }
        if ($format === OutputFormat::Webp && !(imagetypes() & IMG_WEBP)) {
            self::markTestSkipped('GD lacks WebP support.');
        }

        self::assertStringStartsWith($magic, Barcode::code128('Hello')->render($format));
    }

    /**
     * @return iterable<string, array{OutputFormat, non-empty-string}>
     */
    public static function formats(): iterable
    {
        yield 'svg' => [OutputFormat::Svg, '<?xml'];
        yield 'png' => [OutputFormat::Png, "\x89PNG"];
        yield 'jpeg' => [OutputFormat::Jpeg, "\xFF\xD8"];
        yield 'gif' => [OutputFormat::Gif, 'GIF8'];
        yield 'webp' => [OutputFormat::Webp, 'RIFF'];
        yield 'pdf' => [OutputFormat::Pdf, '%PDF-1.4'];
        yield 'eps' => [OutputFormat::Eps, '%!PS-Adobe-3.0 EPSF-3.0'];
        yield 'text' => [OutputFormat::Text, ' '];
    }

    public function testDataUri(): void
    {
        self::assertStringStartsWith('data:image/svg+xml;base64,', Barcode::code128('A')->toDataUri());
    }

    public function testStringCastIsInlineSvg(): void
    {
        $svg = (string) Barcode::code128('A');

        self::assertStringStartsWith('<svg ', $svg);
    }

    public function testSaveInfersFormatFromExtension(): void
    {
        $path = sys_get_temp_dir() . '/php-barcode-test-' . bin2hex(random_bytes(4)) . '.svg';
        try {
            Barcode::code128('Saved')->save($path);
            self::assertStringContainsString('<svg', (string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function testCustomSymbologiesWorkWithCreate(): void
    {
        $barcode = Barcode::create(new Code128(), 'X', new RenderOptions(showText: false));

        self::assertFalse($barcode->options->showText);
    }
}
