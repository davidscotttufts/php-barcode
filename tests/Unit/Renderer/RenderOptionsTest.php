<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Renderer;

use PhpBarcode\Renderer\Color;
use PhpBarcode\Renderer\Orientation;
use PhpBarcode\Renderer\RenderOptions;
use PHPUnit\Framework\TestCase;

final class RenderOptionsTest extends TestCase
{
    public function testWithersReturnModifiedCopies(): void
    {
        $base = new RenderOptions();
        $changed = $base->withColors('#f00', '#00ff0080')->withOrientation(Orientation::Vertical)->withoutText();

        self::assertSame('#000000', $base->foreground->toHex());
        self::assertSame('#ff0000', $changed->foreground->toHex());
        self::assertEqualsWithDelta(0.502, $changed->background->alpha, 0.001);
        self::assertSame(Orientation::Vertical, $changed->orientation);
        self::assertFalse($changed->showText);
        self::assertTrue($base->showText);
    }

    public function testForPrintConvertsMillimetres(): void
    {
        $options = RenderOptions::forPrint(moduleMm: 0.254, barHeightMm: 25.4, dpi: 300);

        self::assertEqualsWithDelta(3.0, $options->moduleWidth, 1e-9);
        self::assertEqualsWithDelta(300.0, $options->barHeight, 1e-9);
        self::assertSame(300, $options->effectiveDpi());
    }

    public function testRejectsNonsense(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RenderOptions(moduleWidth: 0);
    }

    public function testColorParsing(): void
    {
        self::assertEquals(new Color(255, 136, 0), Color::fromHex('f80'));
        self::assertSame(127, Color::transparent()->gdAlpha());
        $this->expectException(\InvalidArgumentException::class);
        Color::fromHex('#12');
    }
}
