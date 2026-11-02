<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Renderer\RenderOptions;
use PhpBarcode\Symbol\LinearSymbol;
use PhpBarcode\Symbology\AbstractEan;
use PhpBarcode\Symbology\BearerBars;
use PhpBarcode\Symbology\Ean13;
use PhpBarcode\Symbology\Ean2;
use PhpBarcode\Symbology\Ean5;
use PhpBarcode\Symbology\Ean8;
use PhpBarcode\Symbology\Isbn;
use PhpBarcode\Symbology\Ismn;
use PhpBarcode\Symbology\Issn;
use PhpBarcode\Symbology\Itf14;
use PhpBarcode\Symbology\UpcA;
use PhpBarcode\Symbology\UpcE;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetailSymbologyTest extends TestCase
{
    public function testEan13CalculatesAndVerifiesCheckDigit(): void
    {
        self::assertSame('4006381333931', (new Ean13())->encode('400638133393')->text());
        self::assertSame('4006381333931', (new Ean13())->encode('4 006381 333931')->text());

        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage('expected "1", got "2"');
        (new Ean13())->encode('4006381333932');
    }

    public function testEan13RetailTextLayout(): void
    {
        $layout = (new Ean13())->encode('400638133393')->layout(new RenderOptions(moduleWidth: 2, barHeight: 50, fontSize: 14, textGap: 4));

        self::assertNull($layout->text);
        self::assertSame(['4', '006381', '333931'], array_column($layout->texts, 'text'));
        self::assertSame(['end', 'middle', 'middle'], array_column($layout->texts, 'anchor'));

        // Quiet zones 11 (left) + 95 modules + 7 (right).
        self::assertSame((11 + 95 + 7) * 2.0, $layout->width);
        // The first digit sits in the left quiet zone; the groups are centered under each half.
        self::assertSame((11 - 2) * 2.0, $layout->texts[0]['x']);
        self::assertSame((11 + 24) * 2.0, $layout->texts[1]['x']);
        self::assertSame((11 + 71) * 2.0, $layout->texts[2]['x']);
        self::assertSame(54.0, $layout->texts[1]['y']);
    }

    public function testGuardBarsExtendIntoTheTextArea(): void
    {
        $layout = (new Ean13())->encode('400638133393')->layout(new RenderOptions(moduleWidth: 1, barHeight: 50, fontSize: 14, textGap: 4));

        $heights = array_count_values(array_map(static fn(array $r): string => (string) $r['height'], $layout->rects));
        // 6 guard bars (2 start, 2 center, 2 end) extend by the gap (4) plus half the font size;
        // retail digits are capped at 10× the module width, so 4 + 10 / 2 = 9 pixels.
        self::assertSame(['59' => 6, '50' => 24], $heights);
    }

    public function testWithoutTextAllBarsHaveTheSameHeight(): void
    {
        $layout = (new Ean13())->encode('400638133393')->layout(new RenderOptions(barHeight: 50, showText: false));

        self::assertSame([50.0], array_values(array_unique(array_column($layout->rects, 'height'))));
        self::assertSame([], $layout->texts);
        self::assertSame(50.0, $layout->height);
    }

    public function testUpcALayoutPutsOuterDigitsOutsideTheBars(): void
    {
        $symbol = (new UpcA())->encode('03600029145');
        $layout = $symbol->layout(new RenderOptions(moduleWidth: 1));

        self::assertSame('036000291452', $symbol->text());
        self::assertSame(['0', '36000', '29145', '2'], array_column($layout->texts, 'text'));
        self::assertSame((9 + 95 + 9) * 1.0, $layout->width);
    }

    public function testEan8Layout(): void
    {
        $layout = (new Ean8())->encode('9638507')->layout(new RenderOptions());

        self::assertSame(['9638', '5074'], array_column($layout->texts, 'text'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addOnInputs(): iterable
    {
        yield 'plus' => ['9780306406157+51299', '9780306406157 51299'];
        yield 'space' => ['9780306406157 51299', '9780306406157 51299'];
        yield 'no check digit' => ['978030640615+12', '9780306406157 12'];
        yield 'hyphenated' => ['978-0-306-40615-7 51299', '9780306406157 51299'];
    }

    #[DataProvider('addOnInputs')]
    public function testAddOnSyntax(string $input, string $text): void
    {
        self::assertSame($text, (new Ean13())->encode($input)->text());
    }

    public function testAddOnBarsStartLowerWithTextAbove(): void
    {
        $symbol = (new Ean13())->encodeLinear('9780306406157+51299');
        $layout = $symbol->layout(new RenderOptions(moduleWidth: 1, barHeight: 50, fontSize: 10, textGap: 2));

        self::assertSame(95 + 7, $symbol->addOnStart);
        $addOnText = $layout->texts[3];
        self::assertSame('51299', $addOnText['text']);
        self::assertSame(0.0, $addOnText['y']);

        $addOnBars = array_filter($layout->rects, static fn(array $r): bool => $r['x'] >= 11 + 102);
        self::assertNotEmpty($addOnBars);
        foreach ($addOnBars as $bar) {
            self::assertSame(12.5, $bar['y']);
        }
        // Right quiet zone after an add-on is 5 modules.
        self::assertSame(11.0 + 102 + 47 + 5, $layout->width);
    }

    public function testAddOnGapMustFollowGs1(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UpcA(addOnGap: 7);
    }

    public function testSplitAddOn(): void
    {
        self::assertSame(['9780306406157', '51299'], AbstractEan::splitAddOn('9780306406157+51299'));
        self::assertSame(['4 006381 333931', null], AbstractEan::splitAddOn('4 006381 333931'));
    }

    public function testEanAddOnParity(): void
    {
        // EAN-2 parity follows value mod 4; EAN-5 parity follows its weighted checksum.
        self::assertSame('10110011001010010011', (new Ean2())->encodeLinear('12')->toBinary());
        self::assertSame(
            '10110110001010110011010010011010001011010010111',
            (new Ean5())->encodeLinear('51299')->toBinary(),
        );
    }

    public function testInvalidAddOnLengthIsRejected(): void
    {
        $this->expectException(InvalidDataException::class);
        (new Ean2())->encode('123');
    }

    public function testUpcEExpansionAndCompression(): void
    {
        self::assertSame('04210000526', UpcE::expand('0425261'));
        self::assertSame('01200000345', UpcE::expand('0123450'));
        self::assertSame('01230000045', UpcE::expand('0123453'));
        self::assertSame('01234000005', UpcE::expand('0123454'));
        self::assertSame('01234500007', UpcE::expand('0123457'));

        foreach (['0425261', '0123450', '0123453', '0123454', '0123457', '1234565'] as $upcE) {
            self::assertSame($upcE, UpcE::compress(UpcE::expand($upcE)));
        }
        self::assertNull(UpcE::compress('03600029145'));
    }

    public function testUpcEAcceptsSeveralInputForms(): void
    {
        $upcE = new UpcE();
        $expected = $upcE->encode('0425261')->toGrid();

        self::assertSame('04252614', $upcE->encode('425261')->text());
        self::assertSame($expected, $upcE->encode('04252614')->toGrid());
        self::assertSame($expected, $upcE->encode('04210000526')->toGrid());
        self::assertSame($expected, $upcE->encode('042100005264')->toGrid());
    }

    public function testUpcERejectsBadInput(): void
    {
        $upcE = new UpcE();

        self::assertFalse($upcE->supports('2425261'), 'number system must be 0 or 1');
        self::assertFalse($upcE->supports('04252615'), 'wrong check digit');
        self::assertFalse($upcE->supports('03600029145'), 'not zero-suppressible');
    }

    public function testIsbn(): void
    {
        $isbn = new Isbn();
        $symbol = $isbn->encode('ISBN 0-306-40615-2');

        self::assertInstanceOf(LinearSymbol::class, $symbol);
        self::assertSame('ISBN', $symbol->symbology());
        self::assertSame('9780306406157', $symbol->text());
        self::assertSame((new Ean13())->encode('9780306406157')->toGrid(), $symbol->toGrid());
        self::assertContains('ISBN 9780306406157', array_column($symbol->textSegments, 'text'));

        self::assertSame('X', Isbn::isbn10Check('080442957'));
        self::assertTrue($isbn->supports('080442957X'));
        self::assertFalse($isbn->supports('0-306-40615-3'));
        self::assertFalse($isbn->supports('9770306406157'));
        self::assertSame('9780306406157 51299', $isbn->encode('978-0-306-40615-7 51299')->text());
    }

    public function testIssn(): void
    {
        $symbol = (new Issn())->encode('0317-8471');

        self::assertSame('9770317847001', $symbol->text());
        self::assertSame('9770317847056', (new Issn('05'))->encode('03178471')->text());
        self::assertSame('X', Issn::check('2434561'));
        self::assertFalse((new Issn())->supports('0317-8472'));
    }

    public function testIsmn(): void
    {
        self::assertSame('9790230671187', (new Ismn())->encode('M-2306-7118-7')->text());
        self::assertSame('9790230671187', (new Ismn())->encode('979-0-2306-7118-7')->text());
        self::assertFalse((new Ismn())->supports('M-2306-7118-8'));
    }

    public function testItf14BearerBars(): void
    {
        $options = new RenderOptions(moduleWidth: 1, barHeight: 50, showText: false);
        $plain = (new Itf14(BearerBars::None))->encode('1540014128876')->layout($options);
        $horizontal = (new Itf14(BearerBars::Horizontal, 4))->encode('1540014128876')->layout($options);
        $box = (new Itf14(BearerBars::Box, 4))->encode('1540014128876')->layout($options);

        self::assertCount(count($plain->rects) + 2, $horizontal->rects);
        self::assertCount(count($plain->rects) + 4, $box->rects);
        self::assertSame($plain->width, $horizontal->width);
        self::assertSame($plain->width + 8, $box->width);
        self::assertSame(58.0, $box->height);
    }

    public function testItf14CheckDigit(): void
    {
        self::assertSame('15400141288763', (new Itf14())->encode('1540014128876')->text());
        self::assertFalse((new Itf14())->supports('15400141288764'));
        $this->expectException(\InvalidArgumentException::class);
        new Itf14(bearerWidth: 1);
    }
}
