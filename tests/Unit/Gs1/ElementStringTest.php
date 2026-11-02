<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Gs1;

use PhpBarcode\Gs1\ElementString;
use PhpBarcode\Gs1\Gs1Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ElementStringTest extends TestCase
{
    private const GS = "\x1D";

    public function testParsesBracketedInput(): void
    {
        $elements = ElementString::parse('(01)09506000134352(17)251231(10)ABC123(21)XYZ');

        self::assertSame(['01' => '09506000134352', '17' => '251231', '10' => 'ABC123', '21' => 'XYZ'], $elements->toArray());
        self::assertSame('(01)09506000134352(17)251231(10)ABC123(21)XYZ', $elements->toHri());
        self::assertSame('(01)09506000134352(17)251231(10)ABC123(21)XYZ', (string) $elements);
    }

    public function testEncodableFormSeparatesOnlyVariableLengthFields(): void
    {
        $elements = ElementString::parse('(01)09506000134352(10)ABC123(17)251231(21)XYZ');

        // (01) and (17) are predefined-length; (10) is followed by a separator; the last field never is.
        self::assertSame('0109506000134352' . '10ABC123' . self::GS . '17251231' . '21XYZ', $elements->toEncodable());
    }

    public function testParsesRawInputWithSeparators(): void
    {
        $elements = ElementString::parse('01095060001343521725123110ABC123' . self::GS . '21XYZ');

        self::assertSame('(01)09506000134352(17)251231(10)ABC123(21)XYZ', $elements->toHri());
    }

    #[DataProvider('symbologyIdentifiers')]
    public function testStripsSymbologyIdentifiers(string $identifier): void
    {
        self::assertSame('(01)09506000134352', ElementString::parse($identifier . '0109506000134352')->toHri());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function symbologyIdentifiers(): iterable
    {
        foreach ([']C1', ']e0', ']d2', ']Q3', ']J1'] as $identifier) {
            yield $identifier => [$identifier];
        }
    }

    public function testIgnoresLeadingFnc1AndRedundantSeparators(): void
    {
        $elements = ElementString::parse(self::GS . '0109506000134352' . self::GS . '10ABC');

        self::assertSame('(01)09506000134352(10)ABC', $elements->toHri());
    }

    public function testAcceptsSquareBrackets(): void
    {
        self::assertSame('(01)09506000134352(10)A1', ElementString::parse('[01]09506000134352[10]A1')->toHri());
    }

    public function testEscapedParenthesisIsPartOfTheValue(): void
    {
        $elements = ElementString::parse('(01)09506000134352(10)AB\(C)');

        self::assertSame('AB(C)', $elements->get('10'));
    }

    public function testUnknownBracketedCodeIsTreatedAsData(): void
    {
        // "(23)" is not an AI, so it stays inside the batch number.
        self::assertSame('A(23)B', ElementString::parse('(01)09506000134352(10)A(23)B')->get('10'));
    }

    public function testFromArrayRestoresLeadingZeroKeys(): void
    {
        $elements = ElementString::fromArray([01 => '09506000134352', '17' => 251231, 10 => 'ABC']);

        self::assertSame('(01)09506000134352(17)251231(10)ABC', $elements->toHri());
    }

    public function testOptimalOrderPutsPredefinedLengthFieldsFirst(): void
    {
        $elements = ElementString::parse('(10)ABC(01)09506000134352(21)S1(17)251231')->withOptimalOrder();

        self::assertSame('(01)09506000134352(17)251231(10)ABC(21)S1', $elements->toHri());
        self::assertSame('01095060001343521725123110ABC' . self::GS . '21S1', $elements->toEncodable());
    }

    public function testAccessors(): void
    {
        $elements = ElementString::parse('(01)09506000134352(10)ABC');

        self::assertTrue($elements->has('10'));
        self::assertFalse($elements->has('21'));
        self::assertNull($elements->get('21'));
        self::assertCount(2, $elements->elements);
        self::assertSame('BATCH/LOT', $elements->elements[1]->ai->title);
    }

    public function testRoundTripsBetweenForms(): void
    {
        $original = ElementString::parse('(01)09506000134352(3103)000500(10)L1(400)PO-77(21)X9');

        self::assertSame($original->toHri(), ElementString::parse($original->toEncodable())->toHri());
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInput(string $input, string $message): void
    {
        $this->expectException(Gs1Exception::class);
        $this->expectExceptionMessage($message);

        ElementString::parse($input);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'bad GTIN check digit' => ['(01)09506000134353', 'AI (01): invalid check digit at position 14, expected 2'];
        yield 'bad SSCC check digit' => ['(00)106141412345678909', 'AI (00): invalid check digit at position 18, expected 8'];
        yield 'GTIN too short' => ['(01)0950600013435', 'AI (01): value is too short: 13 characters, format N14 requires 14'];
        yield 'batch too long' => ['(01)09506000134352(10)ABCDEFGHIJKLMNOPQRSTU', 'AI (10): value is too long: 21 characters, format X..20 allows at most 20'];
        yield 'letters in a numeric AI' => ['(01)09506000134352(3103)00123x', 'AI (3103): invalid character "x" at position 6, digits only allowed'];
        yield 'character outside CSET 82' => ['(01)09506000134352(10)AB#C', 'AI (10): invalid character "#" at position 3, GS1 character set 82 allowed'];
        yield 'invalid month' => ['(01)09506000134352(17)251331', 'AI (17): invalid month "13" at position 3'];
        yield 'invalid day' => ['(01)09506000134352(15)250230', 'AI (15): invalid day "30" at position 5'];
        yield 'zero day not allowed in (7006)' => ['(01)09506000134352(7006)250200', 'AI (7006): invalid day "00" at position 5'];
        yield 'invalid hour' => ['(01)09506000134352(7003)2501012460', 'AI (7003): invalid hour "24" at position 7'];
        yield 'unknown country' => ['(01)09506000134352(422)999', 'AI (422): "999" is not an ISO 3166 numeric country code at position 1'];
        yield 'unknown currency' => ['(01)09506000134352(30)1(3932)0401234', 'AI (3932): "040" is not an ISO 4217 numeric currency code at position 1'];
        yield 'bad IBAN' => ['(415)5412345678908(8020)A(8007)GB82WEST12345698765433', 'AI (8007): invalid IBAN check digits at position 3'];
        yield 'bad GMN check pair' => ['(8013)1987654Ad4X4bL5ttr2310c2X', 'AI (8013): invalid check character pair at position 24, expected 2K'];
        yield 'piece exceeds total' => ['(8006)095060001343520302', 'AI (8006): piece number exceeds the total number of pieces at position 15'];
        yield 'yes/no flag' => ['(00)106141412345678908(4321)2', 'AI (4321): must be 0 (no) or 1 (yes) at position 1'];
        yield 'empty value' => ['(01)09506000134352(10)', 'AI (10): value is empty'];
        yield 'unknown AI (raw)' => ['2312345', 'No known GS1 Application Identifier at position 1 ("2312")'];
        yield 'no AI at start' => ['ABC(01)09506000134352', 'No known GS1 Application Identifier at position 1'];
        yield 'missing requisite' => ['(10)ABC', 'AI (10) requires (01) or (02) or (03) or (8006) or (8026)'];
        yield 'missing combined requisite' => ['(01)09506000134352(250)X', 'AI (250) requires (01) + (21) or (03) + (21) or (8006) + (21)'];
        yield 'mutually exclusive AIs' => ['(01)09506000134352(02)09506000134352(37)5', 'AI (01) must not be used together with AI (37)'];
        yield 'exclusive pattern (310n)' => ['(01)09506000134352(3102)000100(3103)000500', 'AI (3102) must not be used together with AI (3103)'];
        yield 'conflicting duplicates' => ['(01)09506000134352(10)A(10)B', 'AI (10) appears more than once with different values'];
        yield 'empty input' => ['', 'GS1 data is empty'];
    }

    public function testAssociationChecksCanBeDisabled(): void
    {
        self::assertSame('(10)ABC', ElementString::parse('(10)ABC', checkAssociations: false)->toHri());
    }

    public function testIdenticalDuplicatesAreAllowed(): void
    {
        self::assertSame('A', ElementString::parse('(01)09506000134352(10)A(10)A')->get('10'));
    }

    public function testExceptionCarriesAiAndPosition(): void
    {
        try {
            ElementString::parse('(01)09506000134353');
            self::fail('Expected a Gs1Exception.');
        } catch (Gs1Exception $e) {
            self::assertSame('01', $e->ai);
            self::assertSame(14, $e->position);
        }
    }

    #[DataProvider('validInputs')]
    public function testAcceptsValidInput(string $input): void
    {
        self::assertSame($input, ElementString::parse($input)->toHri());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validInputs(): iterable
    {
        yield 'SSCC' => ['(00)106141412345678908'];
        yield 'GTIN with dates' => ['(01)09506000134352(11)250101(13)250102(15)251200(17)251231'];
        yield 'leap day' => ['(01)09506000134352(15)240229'];
        yield 'variable count' => ['(02)09506000134352(37)25(00)106141412345678908'];
        yield 'net weight kg' => ['(01)09506000134352(3103)000500'];
        yield 'price with currency' => ['(01)09506000134352(30)1(3932)9780401234'];
        yield 'ship to postal code with country' => ['(00)106141412345678908(421)840123456789'];
        yield 'production date and time' => ['(01)09506000134352(8008)25010112'];
        yield 'GMN' => ['(8013)1987654Ad4X4bL5ttr2310c2K'];
        yield 'GDTI with serial' => ['(253)9506000134352ABC'];
        yield 'GRAI' => ['(8003)095060001343521234'];
        yield 'GIAI' => ['(8004)95060001ABC-123'];
        yield 'GSRN provider' => ['(8017)950600013435200008'];
        yield 'ITIP' => ['(8006)095060001343520102(10)A'];
        yield 'payment slip' => ['(415)5412345678908(8020)INV-0001(3900)1999'];
        yield 'IBAN' => ['(415)5412345678908(8020)A(8007)GB82WEST12345698765432'];
        yield 'max temperature with sign' => ['(00)106141412345678908(4330)001234-'];
        yield 'internal company codes' => ['(90)ANY*CHARS(91)X(99)Y'];
        yield 'digital signature (base64url)' => ['(00)106141412345678908(8030)SGVsbG8_LQ=='];
        yield 'percent-encoded name' => ['(00)106141412345678908(4301)JOHN%20DOE'];
        yield 'coordinates' => ['(00)106141412345678908(4309)17999999993599999999'];
        yield 'birth sequence' => ['(8018)950600013435200008(7258)1/2(7259)A'];
    }
}
