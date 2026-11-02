<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Renderer;

use PhpBarcode\Barcode;
use PhpBarcode\Renderer\EpsRenderer;
use PhpBarcode\Renderer\PdfRenderer;
use PhpBarcode\Renderer\RenderOptions;
use PhpBarcode\Renderer\Style\Gradient;
use PhpBarcode\Renderer\Style\Logo;
use PhpBarcode\Renderer\Style\ModuleShape;
use PhpBarcode\Renderer\SvgRenderer;
use PhpBarcode\Symbol\MatrixSymbol;
use PHPUnit\Framework\TestCase;

final class VectorRendererTest extends TestCase
{
    public function testSvgUsesMillimetresWhenDpiIsSet(): void
    {
        $svg = Barcode::of('ean13', '400638133393', RenderOptions::forPrint(0.33, 22.85, 600))->toSvg();

        self::assertMatchesRegularExpression('/width="37\.29mm"/', $svg);
    }

    public function testPdfIsStructurallyValid(): void
    {
        $pdf = (new PdfRenderer())->render(Barcode::code128('PDF')->symbol, new RenderOptions());

        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringEndsWith("%%EOF\n", $pdf);
        self::assertSame(1, preg_match('/startxref\n(\d+)\n/', $pdf, $m));
        self::assertSame('xref', substr($pdf, (int) ($m[1] ?? 0), 4));
    }

    public function testEpsHasBoundingBox(): void
    {
        $eps = (new EpsRenderer())->render(Barcode::code128('EPS')->symbol, new RenderOptions());

        self::assertMatchesRegularExpression('/%%BoundingBox: 0 0 \d+ \d+/', $eps);
    }

    public function testStyledMatrixSymbolsUseCurvesAndGradients(): void
    {
        $symbol = self::fakeQr();
        $options = (new RenderOptions(moduleWidth: 10))
            ->withModuleShape(ModuleShape::Dot)
            ->withGradient(Gradient::radial('#000', '#00f'));

        $svg = (new SvgRenderer())->render($symbol, $options);

        self::assertStringContainsString('<radialGradient', $svg);
        self::assertStringContainsString('fill="url(#barcode-fill)"', $svg);
        self::assertStringNotContainsString('crispEdges', $svg);
    }

    public function testLogoPunchoutRemovesCenterModules(): void
    {
        $symbol = self::fakeQr();
        $logo = new Logo('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"/>', 'image/svg+xml', size: 0.3);

        $styled = (new RenderOptions(moduleWidth: 10))->withModuleShape(ModuleShape::Rounded);
        $plain = (new SvgRenderer())->render($symbol, $styled);
        $withLogo = (new SvgRenderer())->render($symbol, $styled->withLogo($logo));

        self::assertStringContainsString('<image ', $withLogo);
        self::assertLessThan(substr_count($plain, 'M'), substr_count($withLogo, 'M'));
    }

    /**
     * A solid 21×21 matrix with QR-style finders, good enough to exercise styling.
     */
    private static function fakeQr(): MatrixSymbol
    {
        return new MatrixSymbol(
            array_fill(0, 21, str_repeat('1', 21)),
            'fake',
            'Fake QR',
            4,
            1,
            [['row' => 0, 'column' => 0, 'size' => 7], ['row' => 0, 'column' => 14, 'size' => 7], ['row' => 14, 'column' => 0, 'size' => 7]],
        );
    }
}
