<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\LinearSymbol;
use PhpBarcode\Symbology\Code128;
use PhpBarcode\Symbology\Code128Subset;
use PhpBarcode\Symbology\Gs1128;
use PHPUnit\Framework\TestCase;

final class Gs1128Test extends TestCase
{
    private const START_C = '211232';
    private const FNC1 = '411131';

    public function testStartsWithFnc1AndUsesCodeSetCForNumericData(): void
    {
        $symbol = $this->encode('(00)106141412345678908');

        // Start C, FNC1, nine digit pairs, check character, stop.
        self::assertStringStartsWith(self::START_C . self::FNC1, $this->pattern($symbol));
        self::assertSame((2 + 10 + 1) * 11 + 13, $symbol->totalModules());
        self::assertSame('(00)106141412345678908', $symbol->text());
        self::assertSame('GS1-128', $symbol->symbology());
    }

    public function testSeparatesVariableLengthFieldsWithFnc1(): void
    {
        $values = $this->symbolValues($this->encode('(01)09506000134352(10)AB(21)12'));

        // Start C, FNC1, 01 09 50 60 00 13 43 52 10, Code B, A, B, FNC1, Code C, 21 12, check, stop.
        self::assertSame([105, 102, 1, 9, 50, 60, 0, 13, 43, 52, 10, 100, 33, 34, 102, 99, 21, 12], array_slice($values, 0, -1));
    }

    public function testGoldenPattern(): void
    {
        $symbol = $this->encode('(01)09506000134352(17)251231(10)ABC123');

        self::assertSame('7f565d51d58e58ec282a41fb02450a8935a99da0', sha1($symbol->toBinary()));
        self::assertSame('(01)09506000134352(17)251231(10)ABC123', $symbol->text());
    }

    public function testAcceptsRawInput(): void
    {
        self::assertSame(
            $this->encode('(01)09506000134352(10)AB(21)12')->widths,
            $this->encode('0109506000134352' . "10AB\x1D" . '2112')->widths,
        );
    }

    public function testOptionallyReordersForCompactness(): void
    {
        $plain = $this->encode('(10)AB(01)09506000134352');
        $optimized = $this->encode('(10)AB(01)09506000134352', new Gs1128(optimizeOrder: true));

        self::assertLessThan($plain->totalModules(), $optimized->totalModules());
        self::assertSame('(01)09506000134352(10)AB', $optimized->text());
    }

    public function testReportsGs1ValidationErrors(): void
    {
        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage('GS1-128: AI (01): invalid check digit at position 14, expected 2');

        $this->encode('(01)09506000134353');
    }

    public function testEnforcesTheFortyEightCharacterLimit(): void
    {
        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage('at most 48 data characters');

        $this->encode('(8007)GB82WEST12345698765432(415)5412345678908(8020)ABC');
    }

    public function testAssociationChecksCanBeDisabled(): void
    {
        self::assertFalse((new Gs1128())->supports('(10)ABC'));
        self::assertTrue((new Gs1128(checkAssociations: false))->supports('(10)ABC'));
    }

    public function testCode128WithoutFnc1EncodesGsAsAControlCharacter(): void
    {
        $values = $this->symbolValues((new Code128())->encode("A\x1DB"));

        // Start A, A, GS (value 93 in set A), B.
        self::assertSame([103, 33, 93, 34], array_slice($values, 0, -1));
    }

    public function testCode128Fnc1InForcedSubsets(): void
    {
        self::assertSame(
            [105, 102, 1, 23, 102, 10, 12],
            array_slice($this->symbolValues((new Code128(Code128Subset::C, fnc1: true))->encode("0123\x1D1012")), 0, -1),
        );
        self::assertSame(
            [104, 102, 17, 16, 33, 102, 18],
            array_slice($this->symbolValues((new Code128(Code128Subset::B, fnc1: true))->encode("10A\x1D2")), 0, -1),
        );
    }

    public function testCode128SubsetCWithFnc1RequiresEvenRuns(): void
    {
        self::assertFalse((new Code128(Code128Subset::C, fnc1: true))->supports("012\x1D10"));
    }

    private function encode(string $data, Gs1128 $symbology = new Gs1128()): LinearSymbol
    {
        $symbol = $symbology->encode($data);
        self::assertInstanceOf(LinearSymbol::class, $symbol);

        return $symbol;
    }

    private function pattern(LinearSymbol $symbol): string
    {
        return implode('', $symbol->widths);
    }

    /**
     * Decodes the symbol back into Code 128 symbol values, to check code-set choices.
     *
     * @return list<int>
     */
    private function symbolValues(\PhpBarcode\Symbol\Symbol $symbol): array
    {
        self::assertInstanceOf(LinearSymbol::class, $symbol);
        $patterns = (new \ReflectionClassConstant(Code128::class, 'PATTERNS'))->getValue();
        self::assertIsArray($patterns);
        $lookup = [];
        foreach ($patterns as $value => $widths) {
            self::assertIsString($widths);
            $lookup[$widths] = (int) $value;
        }

        $pattern = $this->pattern($symbol);
        $values = [];
        for ($offset = 0; $offset + 6 <= strlen($pattern) - 7; $offset += 6) {
            $values[] = $lookup[substr($pattern, $offset, 6)];
        }

        return $values;
    }
}
