<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Symbology;

use PhpBarcode\Renderer\RenderOptions;
use PhpBarcode\Symbol\StackedLinearSymbol;
use PHPUnit\Framework\TestCase;

final class StackedLinearSymbolTest extends TestCase
{
    public function testFixedRowsKeepTheirHeight(): void
    {
        $symbol = new StackedLinearSymbol([
            ['bits' => '101', 'height' => 5],
            ['bits' => '010', 'height' => 1],
            ['bits' => '11', 'height' => 7],
        ], 'T', 'Test', 0);

        $layout = $symbol->layout(new RenderOptions(moduleWidth: 2, barHeight: 100, showText: false));

        self::assertSame(6.0, $layout->width);
        self::assertSame(26.0, $layout->height);
        self::assertSame(['x' => 0.0, 'y' => 0.0, 'width' => 2.0, 'height' => 10.0], $layout->rects[0]);
        self::assertSame(['x' => 2.0, 'y' => 10.0, 'width' => 2.0, 'height' => 2.0], $layout->rects[2]);
        self::assertSame(['x' => 0.0, 'y' => 12.0, 'width' => 4.0, 'height' => 14.0], $layout->rects[3]);
    }

    public function testFlexibleRowsGrowToTheBarHeightButNeverBelowTheirMinimum(): void
    {
        $symbol = new StackedLinearSymbol([
            ['bits' => '1', 'height' => 33, 'flexible' => true],
            ['bits' => '1', 'height' => 1],
            ['bits' => '1', 'height' => 33, 'flexible' => true],
        ], '', 'Test', 0);

        self::assertSame(202.0, $symbol->layout(new RenderOptions(moduleWidth: 1, barHeight: 202, showText: false))->height);
        self::assertSame(67.0, $symbol->layout(new RenderOptions(moduleWidth: 1, barHeight: 10, showText: false))->height);
    }

    public function testGridRepeatsRowsAndPadsWidths(): void
    {
        $symbol = new StackedLinearSymbol([['bits' => '1', 'height' => 2], ['bits' => '101', 'height' => 1]], '', 'Test');

        self::assertSame(['100', '100', '101'], $symbol->toGrid());
        self::assertSame(3, $symbol->width());
    }

    public function testRejectsInvalidRows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new StackedLinearSymbol([['bits' => '1x', 'height' => 1]], '', 'Test');
    }
}
