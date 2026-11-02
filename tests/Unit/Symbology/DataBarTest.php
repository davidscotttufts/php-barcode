<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\LinearSymbol;
use PhpBarcode\Symbol\StackedLinearSymbol;
use PhpBarcode\Symbology\DataBarExpanded;
use PhpBarcode\Symbology\DataBarLimited;
use PhpBarcode\Symbology\DataBarOmni;
use PhpBarcode\Symbology\DataBarOmniLayout;
use PhpBarcode\Symbology\SymbologyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden hashes were recorded from symbols verified to decode with zxing-cpp.
 */
final class DataBarTest extends TestCase
{
    #[DataProvider('goldenSymbols')]
    public function testGoldenSymbols(SymbologyInterface $symbology, string $data, string $sha1, int $rows, int $width, string $text): void
    {
        $symbol = $symbology->encode($data);
        $grid = $symbol->toGrid();

        self::assertSame($sha1, sha1(implode("\n", $grid)));
        self::assertCount($rows, $grid);
        self::assertSame($width, strlen($grid[0]));
        self::assertSame($text, $symbol->text());
    }

    /**
     * @return iterable<string, array{SymbologyInterface, string, string, int, int, string}>
     */
    public static function goldenSymbols(): iterable
    {
        yield 'omnidirectional' => [new DataBarOmni(), '0950600013435', 'cf6732b38def57dc8722956674ff56986196f514', 1, 95, '(01)09506000134352'];
        yield 'truncated' => [new DataBarOmni(DataBarOmniLayout::Truncated), '0950600013435', '3a1b918869e1ca00da801bc8908b39c57fea154e', 13, 96, '(01)09506000134352'];
        yield 'stacked' => [new DataBarOmni(DataBarOmniLayout::Stacked), '0950600013435', 'd51c83cb0562d54a2f1e5125d3f2cdf034ee157d', 13, 50, '(01)09506000134352'];
        yield 'stacked omnidirectional (finder value 3)' => [new DataBarOmni(DataBarOmniLayout::StackedOmnidirectional), '0001000047514', 'c641b8ede52ac487bd5bcf7b578fd6b879d263ed', 69, 50, '(01)00010000475142'];
        yield 'limited' => [new DataBarLimited(), '1501234567890', 'e34391cc5ad988e897d2a9cf2080486400359e58', 1, 73, '(01)15012345678907'];
        yield 'expanded, weight and date compressed' => [new DataBarExpanded(), '(01)98898765432106(3202)012345(15)991231', '6d345bf106be61d93adca87524efe9cf26ceb6e9', 1, 199, '(01)98898765432106(3202)012345(15)991231'];
        yield 'expanded, general field' => [new DataBarExpanded(), '(01)09506000134352(10)ABC123', '95436e415c4c58d2085f7b780da9c60208a1df3d', 1, 230, '(01)09506000134352(10)ABC123'];
        yield 'expanded stacked, 4 segments' => [new DataBarExpanded(4), '(01)98898765432106(3202)012345(15)991231', '8a5486264a307ba83222546293b1acd4b7d61d53', 71, 102, '(01)98898765432106(3202)012345(15)991231'];
        yield 'expanded stacked, 6 segments' => [new DataBarExpanded(6), '(01)09506000134352(17)251231(10)ABC123', '419cea6e11931bcb895881e0d898849641657c54', 71, 151, '(01)09506000134352(17)251231(10)ABC123'];
    }

    #[DataProvider('gtinForms')]
    public function testAcceptsGtinForms(string $input): void
    {
        self::assertSame('(01)09506000134352', (new DataBarOmni())->encode($input)->text());
    }

    /**
     * @return iterable<array{string}>
     */
    public static function gtinForms(): iterable
    {
        yield ['0950600013435'];
        yield ['09506000134352'];
        yield ['(01)09506000134352'];
        yield ['0109506000134352'];
    }

    public function testOmnidirectionalIsNinetySixModulesWide(): void
    {
        $symbol = (new DataBarOmni())->encode('2001234567890');

        self::assertInstanceOf(LinearSymbol::class, $symbol);
        // 96 modules, of which the leading guard space is left to the quiet zone.
        self::assertSame(95, $symbol->totalModules());
    }

    public function testStackedVariantsProduceStackedSymbols(): void
    {
        foreach ([DataBarOmniLayout::Truncated, DataBarOmniLayout::Stacked, DataBarOmniLayout::StackedOmnidirectional] as $layout) {
            self::assertInstanceOf(StackedLinearSymbol::class, (new DataBarOmni($layout))->encode('0950600013435'));
        }
        self::assertInstanceOf(StackedLinearSymbol::class, (new DataBarExpanded(2))->encode('(01)09506000134352(10)A'));
    }

    public function testNames(): void
    {
        self::assertSame('GS1 DataBar Omnidirectional', (new DataBarOmni())->name());
        self::assertSame('GS1 DataBar Truncated', (new DataBarOmni(DataBarOmniLayout::Truncated))->name());
        self::assertSame('GS1 DataBar Stacked', (new DataBarOmni(DataBarOmniLayout::Stacked))->name());
        self::assertSame('GS1 DataBar Stacked Omnidirectional', (new DataBarOmni(DataBarOmniLayout::StackedOmnidirectional))->name());
        self::assertSame('GS1 DataBar Limited', (new DataBarLimited())->name());
        self::assertSame('GS1 DataBar Expanded', (new DataBarExpanded())->name());
        self::assertSame('GS1 DataBar Expanded Stacked', (new DataBarExpanded(4))->name());
    }

    #[DataProvider('invalidData')]
    public function testRejectsInvalidData(SymbologyInterface $symbology, string $data, string $message): void
    {
        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage($message);

        $symbology->encode($data);
    }

    /**
     * @return iterable<string, array{SymbologyInterface, string, string}>
     */
    public static function invalidData(): iterable
    {
        yield 'omni check digit' => [new DataBarOmni(), '09506000134353', 'check digit is invalid: expected "2", got "3"'];
        yield 'omni length' => [new DataBarOmni(), '123', 'requires a 14-digit GTIN'];
        yield 'omni letters' => [new DataBarOmni(), '095060001343A', 'cannot encode character "A"'];
        yield 'limited indicator digit' => [new DataBarLimited(), '2001234567890', 'first digit is 0 or 1'];
        yield 'expanded GS1 error' => [new DataBarExpanded(), '(01)09506000134353', 'AI (01): invalid check digit'];
        yield 'expanded too long' => [new DataBarExpanded(), '(01)09506000134352(10)ABCDEFGHIJKLMNOPQRST(21)ABCDEFGHIJKLMNOPQRST', 'data too long'];
    }

    public function testRejectsOddSegmentCounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DataBarExpanded(3);
    }

    /**
     * @param non-empty-string $methodBits
     */
    #[DataProvider('compressionMethods')]
    public function testSelectsCompressedEncodationMethods(string $data, string $methodBits): void
    {
        $binary = (new \ReflectionMethod(DataBarExpanded::class, 'binary'))->invoke(
            new DataBarExpanded(checkAssociations: false),
            \PhpBarcode\Gs1\ElementString::parse($data, false)->toEncodable(),
        );
        self::assertIsString($binary);

        // Skip the linkage flag; method 1, 2, 5 and 6 headers end in variable-length bits.
        self::assertStringStartsWith($methodBits, substr($binary, 1));
    }

    /**
     * @return iterable<string, array{string, non-empty-string}>
     */
    public static function compressionMethods(): iterable
    {
        yield '1: (01) + other AIs' => ['(01)09506000134352(10)A', '1'];
        yield '2: no (01)' => ['(10)A', '00'];
        yield '3: (01) + (3103)' => ['(01)90012345678908(3103)001750', '0100'];
        yield '4: (01) + (3202)' => ['(01)90012345678908(3202)000156', '0101'];
        yield '5: (01) + (392x)' => ['(01)90012345678908(3922)795', '01100'];
        yield '6: (01) + (393x)' => ['(01)90012345678908(3932)9780401234', '01101'];
        yield '7: (01) + (310x) above 32767 g' => ['(01)90012345678908(3103)032768', '0111000'];
        yield '8: (01) + (320x)' => ['(01)90012345678908(3205)012345', '0111001'];
        yield '13: (01) + (310x) + (17)' => ['(01)90012345678908(3102)012233(17)991231', '0111110'];
        yield '14: (01) + (320x) + (17)' => ['(01)90012345678908(3202)012345(17)991231', '0111111'];
    }
}
