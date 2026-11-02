<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Renderer;

use PhpBarcode\Barcode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CaptionTest extends TestCase
{
    /**
     * @return iterable<string, array{Barcode}>
     */
    public static function barcodes(): iterable
    {
        yield 'linear' => [Barcode::code128('SKU-42')];
        yield 'retail' => [Barcode::ean13('400638133393')];
        yield '2D' => [Barcode::qrCode('https://example.com')];
        yield 'postal' => [Barcode::intelligentMail('01234567094987654321-01234567891')];
        yield 'hexagonal' => [Barcode::of('maxicode', 'Ship to: 1 Infinite Loop')];
        yield 'stacked' => [Barcode::of('databarstacked', '(01)09506000134352')];
    }

    #[DataProvider('barcodes')]
    public function testCaptionReplacesTheDataText(Barcode $barcode): void
    {
        $svg = $barcode->withCaption('Shelf B3 & Widgets')->toSvg();

        self::assertSame(1, substr_count($svg, '<text'));
        self::assertStringContainsString('>Shelf B3 &amp; Widgets</text>', $svg);
    }

    #[DataProvider('barcodes')]
    public function testCaptionAddsSpaceBelowTheSymbol(Barcode $barcode): void
    {
        $plain = $barcode->withoutText()->symbol->layout($barcode->withoutText()->options);
        $svg = $barcode->withCaption('Caption')->toSvg();

        self::assertSame(1, preg_match('/height="([\d.]+)"/', $svg, $m));
        self::assertGreaterThan($plain->height, (float) ($m[1] ?? 0));
    }

    public function testWithoutTextRemovesTheCaption(): void
    {
        self::assertStringNotContainsString('<text', Barcode::code128('X')->withCaption('Hi')->withoutText()->toSvg());
    }

    public function testCaptionInTextOutput(): void
    {
        self::assertStringContainsString('Hello there', Barcode::code128('Hi')->withCaption('Hello there')->toText());
    }

    public function testCaptionIsRotatedWithVerticalBarcodes(): void
    {
        self::assertStringContainsString('rotate(90', Barcode::code128('X')->withCaption('Side')->vertical()->toSvg());
    }

    public function testNonAsciiCaptionsRenderInPng(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD is not available.');
        }

        self::assertStringStartsWith("\x89PNG", Barcode::code128('X')->withCaption('Grüße · 東京')->toPng());
    }
}
