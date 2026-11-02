<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Symbology;

use PhpBarcode\Renderer\RenderOptions;
use PhpBarcode\Symbol\BarState;
use PhpBarcode\Symbol\HeightModulatedSymbol;
use PHPUnit\Framework\TestCase;

final class HeightModulatedSymbolTest extends TestCase
{
    public function testRoundTripsDaftNotation(): void
    {
        $symbol = HeightModulatedSymbol::fromStates('fadt', 'x', 'Test');

        self::assertSame([BarState::Full, BarState::Ascender, BarState::Descender, BarState::Tracker], $symbol->bars);
        self::assertSame('FADT', $symbol->toStates());
        self::assertSame(7, $symbol->totalModules());
    }

    public function testLayoutPlacesEachShapeVertically(): void
    {
        $symbol = HeightModulatedSymbol::fromStates('FADT', '', 'Test', quietZone: 2, trackerTop: 0.25, trackerBottom: 0.75);
        $layout = $symbol->layout(new RenderOptions(moduleWidth: 3, barHeight: 40, showText: false));

        self::assertSame((7 + 4) * 3.0, $layout->width);
        self::assertSame(40.0, $layout->height);

        $extents = array_map(static fn(array $r): array => [$r['x'], $r['y'], $r['y'] + $r['height']], $layout->rects);
        self::assertEquals([
            [6.0, 0.0, 40.0],   // Full
            [12.0, 0.0, 30.0],  // Ascender: top to tracker bottom
            [18.0, 10.0, 40.0], // Descender: tracker top to bottom
            [24.0, 10.0, 30.0], // Tracker
        ], $extents);
    }

    public function testGridShowsTheFourShapes(): void
    {
        $grid = HeightModulatedSymbol::fromStates('FADT', '', 'Test')->toGrid();

        self::assertCount(8, $grid);
        self::assertSame('1010000', $grid[0]);   // top: full + ascender
        self::assertSame('1010101', $grid[3]);   // tracker row: every bar
        self::assertSame('1000100', $grid[7]);   // bottom: full + descender
    }

    public function testRejectsInvalidStates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        HeightModulatedSymbol::fromStates('FAX', '', 'Test');
    }

    public function testRejectsInvertedTracker(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HeightModulatedSymbol([BarState::Full], '', 'Test', trackerTop: 0.7, trackerBottom: 0.3);
    }
}
